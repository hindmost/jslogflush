<?php
/**
 * @package JsLogFlush
 * Integrated JavaScript logging solution
 * 
 * @author  me@savreen.com
 * @license GPL v2 http://opensource.org/licenses/GPL-2.0
 */

/**
 * JsLogFlush server-side implementation
 */
class JsLogFlush
{
    const JS_FILE = <<<CODE
if (!('logflush' in window)) {
(function() {

window.logflush = {
    log: function(s) {
        if (arguments.length <= 1) {
            if (typeof s == 'undefined') flushEx();
            else log(s);
            return;
        }
        var aArg = Array.prototype.slice.call(arguments, 1),
            n = aArg.length, i = 0;
        log(s.replace(/%[difso]/g, function(m) {
            return i < n? outExpr(aArg[i++]) : m;
        }));
    }
};
<%SUBST_CONSOLE%>

// Log session ID:
var ID = '<%ID%>';
// Flush processing URL:
var URL = '<%URL%>';
// Log buffer size (in bytes):
var BUFF_SIZE = <%BUFF_SIZE%>;
// Flush interval: minimal time between two consecutive flush requests
var INTERVAL = <%INTERVAL%>;
// Background flush interval (background flushes aren't initiated by log calls unlike regular flushes)
var INTERVAL_BK = <%INTERVAL_BK%>;

// Queue of log flushes:
var aQueue = [];
// Log buffer:
var sBuff = '';
// Current flush timeout id:
var iTmr = 0;
// Page load timestamp:
var iStamp0 = <%STAMP_INIT%>;
// Current flush starting timestamp:
var iStamp = 0;
var nBkFlag = 0;

function log(s) {
    if (!ID || !s) return false;
    s = (iStamp0? (now()- iStamp0)+ '\\t' : '')+ s+ '\\n';
    if (encodeURIComponent(sBuff + s).length > BUFF_SIZE)
        push2Queue();
    sBuff += s;
    if (aQueue.length) flushEx();
    if (!nBkFlag) {
        nBkFlag = 1;
        setInterval(flushEx, INTERVAL_BK);
    }
    return true;
}

function flush() {
    if (!ID) return;
    if (iTmr) clearTimeout(iTmr);
    iTmr = iStamp = 0;
    if (!aQueue.length && sBuff)
        push2Queue();
    if (!aQueue.length) return;
    send('id='+ID+'&data='+ encodeURIComponent(aQueue.shift()), 1);
    iStamp = now();
    iTmr = setTimeout(flush, INTERVAL);
}

function flushEx() {
    if (!iStamp || now() - iStamp >= INTERVAL) flush();
}

function push2Queue() {
    if (aQueue.length > 30) aQueue.length = 0;
    aQueue.push(sBuff);
    sBuff = '';
}

function send(query) {
<%SEND_FUNC%>
}

function onResponse(v) {
    if (v == '<%RESPONSE_DENY%>') ID = '';
}

function now() {
    return (new Date()).getTime();
}

function outExpr(x) {
    if (typeof x != 'object' || x instanceof Date || x instanceof RegExp)
        return x;
    var s = '';
    for (var key in x)
        s += ', '+ key+ ':'+ outExpr(x[key]);
    return '{'+ (s? s.substr(2) : s)+ '}';
}

})();
}
CODE;

    const JS_SUBST_CONSOLE = <<<CODE
// Substitute console object so console.log() become alias of logflush.log()
window.console = window.logflush;
CODE;

    const JS_STAMP_INIT_ON = 'now()';
    const JS_STAMP_INIT_OFF = '0';

    const JS_FN_SEND = <<<CODE
    var oReq = window.XMLHttpRequest? new XMLHttpRequest() :
        (window.ActiveXObject? new ActiveXObject('Microsoft.XMLHTTP') : null);
    if (!oReq) return;
    oReq.onreadystatechange = function() {
        if (oReq.readyState == 4 && oReq.status == 200 && oReq.responseText)
            onResponse(oReq.responseText);
    };
    oReq.open('POST', URL, true);
    oReq.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    oReq.send(query);
CODE;

    const JS_FN_SEND_PAD = <<<CODE
    var script = document.createElement('script');
    script.src = URL+ '?'+ query+ '&callback=onResponse&_='+ now();
    document.body.appendChild(script);
CODE;

    const MAXLEN_GETDATA = 1948; // max. length of data to send with GET request, bytes (= 2000 - 36 - 13 - 1)
    const MAXLEN_HEAD = 500; // max. length of log session file head section, bytes
    const MAXLEN_APP_URL = 250;
    const MAXLEN_USERAGENT = 150;
    const EPS_INTERVAL = 500; // extra time for each flush request, msecs
    const FILE_REQUESTS = 'lastrequests.dat'; // path of the file to save list of last requests
    const RESPONSE_OK = 'ok';
    const RESPONSE_DENY = 'denied';


    /**
     * @var array - hash of default config option values
     */
    protected $aCfg = array(
        'dir' => '',
        'url' => '',
        'app_urls' => 0,
        'buff_size' => 10000,
        'interval' => 1,
        'interval_bk' => 20,
        'expire' => 1,
        'requests_limit' => 0,
        'log_timeshifts' => 1,
        'subst_console' => 1,
        'minify' => 1,
    );

    /**
     * @var array - hash mapping config option keys to their client option counterparts
     */
    static protected $aClientOpts = array(
        'buff_size' => 'buffSize',
        'interval' => 'flushInterval',
        'interval_bk' => 'bkFlushInterval',
        'log_timeshifts' => 'logTimeshifts',
        'subst_console' => 'substConsole',
        'minify' => 'minify',
    );

    /**
     * @var int - timestamp of the current request's start
     */
    protected $iStamp0 = 0;

    /**
     * @var string - web app URL (obtained from HTTP Referer header)
     */
    protected $sAppUrl = '';

    /**
     * @var string - padding response callback function name
     */
    protected $sCallback = '';


    /**
     * @param array $aCfg - hash of config values defined by the following keys:
     *   'dir' => (string) log files storage directory;
     *   'url' => (string) script URL;
     *   'app_urls' => (array) whitelist of allowable web app URLs;
     *   'buff_size' => (int) buffer size - max/default value;
     *   'interval' => (int) flush interval - min/default value, secs;
     *   'interval_bk' => (int) background flush interval - min/default value, secs;
     *   'expire' => (int) log session expiration time, hours;
     *   'requests_limit' => (int) requests limit per flush interval (0 - no limit);
     *   'log_timeshifts' => (bool) include timeshift (msecs counted from page load timestamp) into each log record;
     *   'subst_console' => (bool) substitute console object with logflush;
     *   'minify' => (bool) minify result JS script
     */
    function __construct($aCfg = false) {
        if (is_array($aCfg)) $this->aCfg = array_merge(
            $this->aCfg, array_intersect_key($aCfg, $this->aCfg)
        );
        $this->iStamp0 = time();
    }

    /**
     * Entry point of requests processing.
     * This method assumes any combination of the following superglobal variables are defined:
     * $_REQUEST['id'] - log session ID, if defined flush request is implied, otherwise initialization request is implied;
     * $_REQUEST['data'] - received content of log buffer, used in flush requests;
     * $_REQUEST['callback'] - name of padding response callback function, used in cross-domain flush requests.
     * @return string|false
     */
    function process() {
        if ($this->limitRequests())
            return;
        if (!$this->aCfg['url'])
            $this->aCfg['url'] = self::buildScriptUrl();
        if ($this->validateAppUrl($v = self::getServerVar('HTTP_REFERER')))
            $this->sAppUrl = $v;
        if ($id = self::getRequestVar('id'))
            return $this->flush($id);
        else
            return $this->init();
    }

    /**
     * initialize log session
     * @return string|false - content of generated JS script
     */
    protected function init() {
        if (!$this->sAppUrl)
            return false;
        $id = $this->generateId();
        $b_in = self::getHost($this->sAppUrl)== self::getServerVar('HTTP_HOST');
        $file = $this->buildFilename($id);
        if (!$file)
            return false;
        file_put_contents($this->aCfg['dir']. $file,
            substr($this->sAppUrl, 0, self::MAXLEN_APP_URL). "\n".
            $this->iStamp0. "\n".
            substr(self::getServerVar('REMOTE_ADDR'), 0, 20). "\n".
            substr(self::getServerVar('HTTP_USER_AGENT'), 0, self::MAXLEN_USERAGENT). "\n".
            "\n\n"
        );
        $a_replc = array(
            'ID' => $id,
            'URL' => $this->aCfg['url'],
            'BUFF_SIZE' => $this->getNumOption('buff_size', true,
                $b_in? false : self::MAXLEN_GETDATA),
            'INTERVAL' => $this->getNumOption('interval', false)
                * 1000+ self::EPS_INTERVAL,
            'INTERVAL_BK' => $this->getNumOption('interval_bk', false)
                * 1000+ self::EPS_INTERVAL,
            'SUBST_CONSOLE' => $this->getBoolOption('subst_console')?
                self::JS_SUBST_CONSOLE : '',
            'STAMP_INIT' => $this->getBoolOption('log_timeshifts')?
                self::JS_STAMP_INIT_ON : self::JS_STAMP_INIT_OFF,
            'SEND_FUNC' => $b_in? self::JS_FN_SEND : self::JS_FN_SEND_PAD,
            'RESPONSE_DENY' => self::RESPONSE_DENY,
        );
        $out = str_replace(
            array_map(array(self, 'wrapTplTag'), array_keys($a_replc)),
            array_values($a_replc),
            self::JS_FILE
        );
        return $this->getBoolOption('minify')? self::minifyJs($out) : $out;
    }

    /**
     * flushes log buffer content to appropriate log session file
     * @param string $id - given log session ID
     * @return string|false
     */
    protected function flush($id) {
        $s_deny = $this->buildResponse(self::RESPONSE_DENY);
        if (!$this->sAppUrl)
            return $s_deny;
        $buff = self::getRequestVar('data');
        if (!$buff)
            return $s_deny;
        if ($v = self::getRequestVar('callback'))
            $this->sCallback = $v;
        $file = $this->buildFilename($id);
        $a_head = $this->readFileHead($this->aCfg['dir']. $file);
        if (!$a_head)
            return $s_deny;
        $url = $a_head[0]; $t = intval($a_head[1]);
        if ($url != $this->sAppUrl ||
            !$t || $this->iStamp0 - $t > $this->aCfg['expire']* 3600)
            return $s_deny;
        file_put_contents($this->aCfg['dir']. $file, $buff, FILE_APPEND);
        return $this->buildResponse(self::RESPONSE_OK);
    }

    /**
     * read header info of log session file
     * @param string $file - given file name
     * @return array|false stacked array with the following indices defined:
     *   0 => web app URL
     *   1 => Unix timestamp (in secs) of log session start
     *   2 => client's IP address
     *   3 => client's user agent
     */
    function readFileHead($file) {
        if (!$file || !is_readable($file))
            return false;
        $hf = fopen($file, 'r');
        $s = fread($hf, self::MAXLEN_HEAD);
        fclose($hf);
        if (!$s)
            return false;
        $arr = array_values(array_filter(explode("\n", $s), 'trim'));
        return count($arr) >= 4? array_slice($arr, 0, 4) : false;
    }

    /**
     * check if file name is a log session file (might be overridden in a subclass)
     * @param string $file - given file name
     * @return bool
     */
    function validateFilename($file) {
        return $file && preg_match('/^[\da-f]{13}[.]log$/', $file);
    }

    /**
     * build file name based on given log session ID (might be overridden in a subclass)
     * @param string $id - given log session ID
     * @return string
     */
    protected function buildFilename($id) {
        return "$id.log";
    }

    /**
     * generate log session ID (might be overridden in a subclass)
     * @return string
     */
    protected function generateId() {
        return uniqid();
    }

    /**
     * restrict number of requests per flush interval
     * @return bool
     */
    protected function limitRequests() {
        $lim = $this->aCfg['requests_limit'];
        if (!$lim)
            return false;
        $file = $this->aCfg['dir']. self::FILE_REQUESTS;
        $a_stamps = array();
        if (is_readable($file) && ($s = file_get_contents($file)) &&
            ($arr = unserialize($s)) && is_array($arr))
            $a_stamps = $arr;
        $a_stamps = array_values(array_filter(
            $a_stamps, array($this, 'filterTimestamp')
        ));
        array_push($a_stamps, $this->iStamp0);
        if (count($a_stamps) > $lim)
            return true;
        file_put_contents($file, serialize($a_stamps));
        return false;
    }

    protected function filterTimestamp($t) {
        return $this->iStamp0 - $t < $this->aCfg['interval'];
    }

    /**
     * check if URL is valid and registered web app URL
     * @param string $url - given URL
     * @return bool
     */
    protected function validateAppUrl($url) {
        if (!$url) return false;
        $a_urls = &$this->aCfg['app_urls'];
        if (!is_array($a_urls)) return true;
        if (!count($a_urls)) return false;
        return in_array(
            self::fixAppUrl($url),
            array_map(array(self, 'fixAppUrl'), $this->aCfg['app_urls'])
        );
    }

    /**
     * apply numeric value of client option to appropriate config option
     * @param string $key - config option key
     * @param string $bUpperLim - use default config value as upper limit
     * @param int $nXtraLim
     * @return int
     */
    protected function getNumOption($key, $bUpperLim, $nXtraLim = false) {
        $lim = intval($this->aCfg[$key]);
        $fn = $bUpperLim? 'min' : 'max';
        if ($nXtraLim !== false) $lim = $fn($lim, intval($nXtraLim));
        return ($v = self::getRequestVar(self::$aClientOpts[$key])) > 0?
            $fn(intval($v), $lim) : $lim;
    }

    /**
     * apply boolean value of client option to appropriate config option
     * @param string $key - config option key
     * @return bool
     */
    protected function getBoolOption($key) {
        return is_null($v = self::getRequestVar(self::$aClientOpts[$key]))?
            $this->aCfg[$key] : (bool)$v;
    }

    /**
     * build response for flush request
     * @param string $s
     * @return string
     */
    protected function buildResponse($s) {
        return $this->sCallback? ($this->sCallback. '("'. $s. '")') : $s;
    }

    /**
     * compress JS code - response for initialization request
     * @param string $text - JS code
     * @return string
     */
    static protected function minifyJs($text) {
        static $a_replc = array(
            '/(^|\n)[ \t]*\/\/[^\n]+/s' => '',
            '/\s+/s' => ' ',
            '/ ([<>][=]?) /s' => '$1',
            '/ ?([.+\-=,;{}():&|?!]+) ?/s' => '$1',
        );
        return str_replace("=','+", "=', '+",
            preg_replace(array_keys($a_replc), array_values($a_replc),
                str_replace("\r\n", "\n", $text)
            ));
    }

    static protected function getHost($url) {
        try {
            $a_nfo = parse_url($url);
        }
        catch (Exception $obj) {
            return false;
        }
        return $a_nfo? $a_nfo['host'] : false;
    }

    /**
     * build URL of flush processing script
     * @return string
     */
    static protected function buildScriptUrl() {
        return 'http://'. self::getServerVar('SERVER_NAME').
            self::stripUrlQuery(self::getServerVar('REQUEST_URI'));
    }

    static protected function getRequestVar($name) {
        return isset($_REQUEST[$name])? $_REQUEST[$name] : null;
    }

    static protected function getServerVar($name) {
        return isset($_SERVER[$name])? $_SERVER[$name] : false;
    }

    static protected function fixAppUrl($url) {
        return self::stripUrlQuery(
            preg_replace('/^https?:\/\/(?:www\.|)/', '', $url, 1)
        );
    }

    static protected function stripUrlQuery($url) {
        return (($i = strpos($url, '?'))? substr($url, 0, $i) : $url);
    }

    static protected function wrapTplTag($s) {
        return '<%'.$s.'%>';
    }
}
