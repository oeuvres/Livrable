<?php  // encoding="UTF-8"
/**

© 2012–2013 frederic.glorieux@fictif.org
© 2013–2015 frederic.glorieux@fictif.org et LABEX OBVIL
LGPL http://www.gnu.org/licenses/lgpl.html

*/

// include needed dependances
include_once(dirname(__FILE__).'/Phips/File.php');

/**
 * Transform TEI in epub
 */
// cli usage
set_time_limit(-1);
if (realpath($_SERVER['SCRIPT_FILENAME']) != realpath(__FILE__)); // file is include do nothing
else if (php_sapi_name() == "cli") {
  Livrable_Tei2epub::cli();
}

class Livrable_Tei2epub {

  /** Static parameters, used for example to communicate between XSL tests and calls */
  private static $pars=array();
  /** file path of source document, used to resolve relative links accros methods */
  private $srcfile;
  /** Source DOM document for TEI file */
  private $srcdoc;
  /** Messages */
  public $log=array();
  /** Debug mode, should be static for the xsl callback */
  private static $debug;
  /** time counter, should be static for the xsl callback */
  private static $time;
  /** A log stream to output events */
  private static $logstream;
  /** XSLT transformer */
  private $trans;
  /** DOM of an XSLT sheet */
  private $xsl;
  /** Different predefined sizes for covers  */
  private static $size=array(
    "small"=>array(150,200),
    "medium"=>array(500,700),
  );


  
  /**
   * Constructor, initialize what is needed
   */
  public function __construct($srcfile, $pars=array(), $logstream=null) {
    self::$pars=array_merge(self::$pars, $pars);
    if (is_a($srcfile, 'DOMDocument')) {
      $this->srcdoc = $srcfile;
    }
    else {
      $this->srcfile=$srcfile;
      self::$pars['srcdir']=dirname($srcfile).'/';
      self::$pars['filename']=pathinfo($srcfile, PATHINFO_FILENAME);
    }
    self::$time = microtime(true);
    if ($logstream) self::$logstream=$logstream;
    $this->xsl = new DOMDocument();
    // inialize an XSLTProcessor
    $this->trans = new XSLTProcessor();
    $this->trans->registerPHPFunctions();
    // allow generation of <xsl:document>
    if (defined('XSL_SECPREFS_NONE')) $prefs = XSL_SECPREFS_NONE;
    else if (defined('XSL_SECPREF_NONE')) $prefs = XSL_SECPREF_NONE;
    else $prefs = 0;
    if(method_exists($this->trans, 'setSecurityPreferences')) $oldval = $this->trans->setSecurityPreferences( $prefs);
    else if(method_exists($this->trans, 'setSecurityPrefs')) $oldval = $this->trans->setSecurityPrefs( $prefs);
    else ini_set("xsl.security_prefs",  $prefs);
  }
  /**
   * Load sourceFile
   */
  public function load() {
    if ($this->srcfile) {
      // for the source doc
      $this->srcdoc=new DOMDocument("1.0", "UTF-8");
      // normalize indentation of XML before all processing
      $xml=file_get_contents($this->srcfile);
      $xml=preg_replace(array('@\r\n@', '@\r@', '@\n[ \t]+@'), array("\n", "\n", "\n"), $xml);
      // $this->doc->recover=true; // no recover, display errors 
      if(!$this->srcdoc->loadXML($xml, LIBXML_NOENT | LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NSCLEAN)) {
         fwrite(self::$logstream, "XML error ".$this->srcfile."\n");
         return false;
      }
    }
    // load 
    $this->srcdoc->preserveWhiteSpace = false;
    $this->srcdoc->formatOutput = true;
    if ($this->srcfile) $this->srcdoc->documentURI = realpath($this->srcfile);
    // should resolve xinclude
    $this->srcdoc->xinclude(LIBXML_NOENT | LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NSCLEAN );
    
    if (self::$debug && self::$logstream) fwrite(self::$logstream, 'load '. round(microtime(true) - self::$time, 3)." s.\n");
    // @xml:id may be used as a href path, example images
    self::$pars['bookname'] = $this->srcdoc->documentElement->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'id');
    if (!self::$pars['bookname']) self::$pars['bookname'] = self::$pars['filename'];
    return $this->srcdoc;
  }
  
  /**
   * Generate an epub from a TEI file
   */
  public function epub($destfile=null, $template=null) {
    if ($destfile);
    // if no destfile and srcfile, build aside srcfile
    else if (isset(self::$pars['srcdir'])) $destfile=self::$pars['srcdir'].self::$pars['filename'].'.epub';
    // in tmp dir
    else  if (isset(self::$pars['filename'])) $destfile=sys_get_temp_dir().'/'.self::$pars['filename'].'.epub';
    // a 
    else $destfile = tempnam(null, 'LIV');
    // if (self::$logstream && $destfile) fwrite(self::$logstream, $this->srcfile.' -> '.$destfile."\n");
    if(!$template) $template = dirname(__FILE__).'/template.epub/';
    $imagesdir = 'Images/';
    $timeStart = microtime(true);
    if (!$this->srcdoc) $this->load(); // srcdoc may have been modified before (ex: naked version)
    $destinfo=pathinfo($destfile);
    // TOTHINK 
    $destdir=$destinfo['dirname'].'/'.$destinfo['filename'].'-epub/';
    Phips_File::newDir($destdir);
    // copy the template folder
    Phips_File::copy($template, $destdir);
    // get the content.opf template
    $opf =  $template . 'OEBPS/.content.opf';
    if (file_exists($f = $template . 'OEBPS/content.opf')) $opf = $f;
    $opf = str_replace('\\', '/', realpath($opf));
    $destdir=str_replace('\\', '/', realpath($destdir) . '/'); // absolute path needed for xsl

    // copy source Dom to local, before modification by images
    // copy referenced images (received modified doc after copy)
    $doc=$this->images($this->srcdoc, $imagesdir, $destdir.'OEBPS/' . $imagesdir);
    if (self::$debug && self::$logstream) fwrite(self::$logstream, 'epub, images '. round(microtime(true) - self::$time, 3)." s.\n");
    // create xhtml pages
    $report = self::transform(
      dirname(__FILE__) . '/xsl/tei2epub.xsl', 
      $doc, 
      null, 
      array(
        'destdir' => $destdir . 'OEBPS/', 
        '_html' => '.xhtml',
        'opf' => $opf,
        'debug' => self::$debug,
      )
    );
    // echo $report->saveXML();
    // cover logic, before opf
    $cover=false;
    if (!isset(self::$pars['srcdir']) || !isset(self::$pars['filename']));
    else if (file_exists(self::$pars['srcdir'].self::$pars['filename'].'.png')) $cover=self::$pars['filename'].'.png';
    else if (file_exists(self::$pars['srcdir'].self::$pars['filename'].'.jpg')) $cover=self::$pars['filename'].'.jpg';
    if ($cover) {
      Phips_File::newDir($destdir.'OEBPS/' . $imagesdir);
      copy(self::$pars['srcdir'].$cover, $destdir.'OEBPS/' . $imagesdir . $cover);
    }
    if ($cover) $params['cover'] =  $imagesdir .$cover;
    // opf file 
    self::transform(
      dirname(__FILE__) . '/xsl/tei2opf.xsl', 
      $doc, 
      $destdir . 'OEBPS/content.opf', 
      array(
        '_html' => '.xhtml',
        'opf' => $opf,
        // 'filename' => self::$pars['filename'],
      )
    );
    if (self::$debug && self::$logstream) fwrite(self::$logstream, 'epub, opf '. round(microtime(true) - self::$time, 3)." s.\n");
    /* ncx not needed in epub3 but useful under firefox epubreader */
    self::transform(
      dirname(__FILE__) . '/xsl/tei2ncx.xsl', 
      $doc, 
      $destdir.'OEBPS/toc.ncx', 
      array(
        '_html'=>'.xhtml', 
        // 'filename' => self::$pars['filename'],
      )
    );
    if (self::$debug && self::$logstream) fwrite(self::$logstream, 'epub, ncx '. round(microtime(true) - self::$time, 3)." s.\n");
    // if (self::$debug) echo $report->saveXML();
    // because PHP zip do not yet allow store without compression (PHP7)
    // an empty epub is prepared with the mimetype
    copy(dirname(__FILE__).'/mimetype.epub', $destfile);
    // zip the dir content
    Phips_File::zip($destfile, $destdir);
    if (!self::$debug) { // delete tmp dir if not debug
      Phips_File::newDir($destdir); // this is a strange behaviour, new dir will empty dir
      rmdir($destdir);
    }
    // if (self::$logstream) fwrite(self::$logstream, number_format(microtime(true) - $timeStart, 3)." s.\n");
    // shall we return entire content of the file ?
    return $destfile;
  }
  /**
   * Extract <graphic> elements from a DOM doc, copy images in a flat destdir
   * $soc : a TEI dom doc, retruned with modified image links
   * $href : a href prefix to redirest generated links
   * $destdir : a folder if images should be copied
   * return : a doc with updated links to image
   */
  public function images($doc, $href=null, $destdir=null) {
    if ($destdir) $destdir=rtrim($destdir, '/\\').'/';
    // copy linked images in an images folder, and modify relative link
    // take $doc by reference
    $doc=$doc->cloneNode(true);
    foreach ($doc->getElementsByTagNameNS('http://www.tei-c.org/ns/1.0', 'graphic') as $el) {
      $this->img($el->getAttributeNode("url"), $href, $destdir);
    }
    /* 
    do not store images of pages, especially in tif
    foreach ($doc->getElementsByTagNameNS('http://www.tei-c.org/ns/1.0', 'pb') as $el) {
      $this->img($el->getAttributeNode("facs"), $hrefTei, $destdir, $hrefSqlite);
    }
    */
    return $doc;
  }
  /**
   * Process one image
   */
  public function img($att, $hrefdir="", $destdir=null) {
    if (!isset($att) || !$att || !$att->value) return;
    $src=$att->value;
    // return if data image
    if (strpos($src, 'data:image') === 0) return;
    
    // test if relative file path
    if (file_exists($test=dirname($this->srcfile).'/'.$src)) $src=$test;
    // vendor specific etc/filename.jpg
    else if (isset(self::$pars['srcdir']) && file_exists($test=self::$pars['srcdir'].self::$pars['filename'].'/'.substr($src, strpos($src, '/')+1))) $src=$test;
    // if not file exists, escape and alert (?)
    else if (!file_exists($src)) {
      $this->log("Image not found: ".$src);
      return;
    }
    $srcParts=pathinfo($src);
    // test first if dst dir (example, epub for sqlite)
    if (isset($destdir)) {
      // create images folder only if images detected
      if (!file_exists($destdir)) Phips_File::newDir($destdir);
      // destination
      $i=2;
      // avoid duplicated files
      while (file_exists($destdir.$srcParts['basename'])) {
        $srcParts['basename']=$srcParts['filename'].'-'.$i.'.'.$srcParts['extension'];
        $i++;
      }
      copy( $src, $destdir.$srcParts['basename']);
    }
    // changes links in TEI so that straight transform will point on the right files
    $att->value=$hrefdir.$srcParts['basename'];
    // resize ?
    // NO delete of <graphic> element if broken link
  }
  
  /**
   * Transform a doc with the provided xslt
   */
  public function transform($xsl, $doc=null, $dst=null, $pars=array()) {
    if (!$doc && isset($this)) {
      $this->load();
      $doc = $this->srcdoc;
    }
    $this->trans->removeParameter('', '_html');
    $this->xsl->load($xsl);
    $this->trans->importStyleSheet($this->xsl);
    // transpose params
    if(isset($pars) && count($pars)) {
      foreach ($pars as $key => $value) {
        $this->trans->setParameter(null, $key, $value);
      }
    }
    $ret=true;
    // changing error_handler allow redirection of <xsl:message>, default behavior is output lines to workaround apache timeout
    $oldError=set_error_handler(array(__CLASS__,"log"), E_WARNING);
    // one output
    if ($dst) $this->trans->transformToURI($doc, $dst);
    // no dst file, return dom, so that piping can continue
    else {
      $ret=$this->trans->transformToDoc($doc);
      // will we have problem here ?
      $ret->formatOutput=true;
      $ret->substituteEntities=true;
    }
    restore_error_handler();
    // reset parameters ! or they will kept on next transform
    if(isset($pars) && count($pars)) foreach ($pars as $key => $value) $this->trans->removeParameter('', $key);
    return $ret;
  }
  /**
   * Handle xsl:message as a custom error handler
   * To avoid Apache time limit, php should output some
   * bytes during long transformations
   */
  static function log( $errno, $errstr=null, $errfile=null, $errline=null, $errcontext=null) {
    if($errstr)$message=$errstr;
    else $message=$errno;
    $message=preg_replace("/XSLTProcessor::transformToUri[^:]*:/", "", $message);
    // default is direct output, maybe better should be found when online
    if (self::$logstream) fwrite(self::$logstream, "\n".$message);
  }

  /**
   * Command line interface for the class 
   */
  public static function cli() {
    $timeStart = microtime(true);
    array_shift($_SERVER['argv']); // shift first arg, the script filepath
    if (!count($_SERVER['argv'])) exit('
    usage    : php -f Tei2epub.php *.xml  destdir/?
');
    $destdir=null;
    $force = false;
    while ($arg=array_shift($_SERVER['argv'])) {
      if ($arg[0]=='-') $arg=substr($arg,1);
      if ($arg=="debug") self::$debug=true; // more log info
      else if ($arg=="force") $force=true; // force epub generation
      else if (isset($destdir)) break;
      else if (isset($srcglob)) $destdir=$arg;
      else $srcglob=$arg;
    }
    if ($destdir) {
      $destdir = rtrim($destdir, '/\\') . '/';
      if ($force) Phips_File::newDir($destdir); // ?
      fwrite(STDERR, "Scan $srcglob, destdir:$destdir\n");
    }
    else fwrite(STDERR, "Scan $srcglob\n");
    Phips_File::scanglob($srcglob, function($srcfile) use($destdir, $force) {
      $pathinfo=pathinfo($srcfile);
      // if destdir desired, flatten destfile
      if ($destdir) $destfile = $destdir.$pathinfo['filename'].'.epub';
      else $destfile = $pathinfo['dirname'].'/'.$pathinfo['filename'].'.epub';
      if (!$force && file_exists($destfile) && filemtime($destfile) > filemtime($srcfile)) return;
      fwrite(STDERR, "$srcfile > $destfile\n");
      // do something
      $livre = new Livrable_Tei2epub($srcfile);
      $livre->epub($destfile);
    });
    fwrite(STDERR, (number_format(microtime(true) - $timeStart, 3))." s.\n");
    exit;
  }

}