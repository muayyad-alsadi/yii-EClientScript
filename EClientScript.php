<?php
/**
 * optimizing client script manager that can minify and combine files (extends CClientScript)
 *
 * @author Muayyad Alsadi <alsadi[at]gmail>
 * @link http://ojuba.org/
 *
 * heavily based on Hightman's EClientScript v1.3
 * @author hightman <hightman2@yahoo.com.cn>
 * @link http://www.czxiu.com/
 * @license http://www.yiiframework.com/license/
 * @version 1.4
 *
 */
/**
  Requirements
  --------------
  Yii 1.1.x or above

  Description:
  --------------
  This extension just extend from {link: CClientScript} using few codes, it will allow you
  to automatically combine all script files and css files into a single (or several) script or css files.
  Basically this will reduce the HTTP calls for resources files by merging several resources files into
  a single (or more) files.
  It can automatically detect the required list of files, and generate a unique filename hash,
  so boldly ease of use.

  ####Css Files:
  CSS files are merged based on there media attribute, background images with a relative path
  in file can also be displayed correctly.

  ####Script files:
  Script files are merged based on their position, If you use the 'CClientScript::POS_HEAD'
  you will end up with a single file for all the script files you've used on that page.
  If you use 'CClientScript::POS_HEAD' and 'CClientScript::POS_END' for example then you'll
  end up with two files for each page on that request, Since those resources are located in different positions.

  ####File optmization or compress (@since: 1.1)
  [CssMin](http://code.google.com/p/cssmin/) used to optmize merged css file. You can set property 'optmizeCssFiles' of the component to enable this feature.
  [JSMinPlus](http://crisp.tweakblogs.net/blog/6861/jsmin%2B-version-14.html) used to optimize merged script file. You can set property 'optmizeScriptFiles' of the component to enable this feature.

  Usage:
  ---------------

  Using this extension is as simple as adding the following code to the application configuration under the components array:

  ~~~
  [php]
  'clientScript' => array(
  'class' => 'ext.minify.EClientScript',
  'combineScriptFiles' => true, // By default this is set to true, change it to false to disable combining JS files
  'combineCssFiles' => true, // By default this is set to true, change it to false to disable combining CSS files
  'optimizeScriptFiles' => true,	// @since: 1.1
  'optimizeCssFiles' => true,	// @since: 1.1
  ),
  ~~~

  Then you'd use the regular 'registerScriptFile' & 'registerCssFile' methods as normal and the files will be combined automatically.

  NOTE:
  ---------------
  If you registered some external resource files that not in the web application root directory, they will be kept and not combined.

  ChangesLog:
  ---------------
  Mar 27, 2013
  * New version number 1.4
  * update JSMinPlus, CssMin
  * use stronger hash for file names
  * consider modification time for calculating hash
  * enable all features by default
  Nov 23, 2010
  * Skip the minimization of files whose names include `.pack.` (and `.min.`)
  * Add the last modification time as the QUERY_STRING to merged file, to avoid not properly flush the browser cache when the file updated.
  Nov 6, 2010
  * New version number 1.3
  * Not repeat the minimization of files those who have been minimized, whose names include `.min.`
  * Fixed `getRelativeUrl()` platform compatibility issue. (thanks to Troto)

  Reporting Issue:
  -----------------
  report issues to https://github.com/muayyad-alsadi/yii-EClientScript/issues

 */

/**
 * Extended clientscript to automatically merge script and css files
 *
 * @author hightman <hightman2@yahoo.com.cn>
 * @version $Id $
 * @package extensions.minify
 * @since 1.0
 */
class EClientScript extends CClientScript
{
	/**
	 * @var combined script file name
	 */
	public $scriptFileName = 'script.js';
	/**
	 * @var combined css stylesheet file name
	 */
	public $cssFileName = 'style.css';
	/**
	 * @var boolean if to combine the script files or not
	 */
	public $combineScriptFiles = true;
	/**
	 * @var boolean if to combine the css files or not
	 */
	public $combineCssFiles = true;
	/**
	 * @var boolean if to optimize the css files
	 */
	public $optimizeCssFiles = true;
	/**
	 * @var boolean if to optimize the script files via googleCompiler(this may cause to much slower)
	 */
	public $optimizeScriptFiles = true;

	/**
	 * Combine css files and script files before renderHead.
	 * @param string the output to be inserted with scripts.
	 */
	public function renderHead(&$output)
	{
		if ($this->combineCssFiles)
			$this->combineCssFiles();

		if ($this->combineScriptFiles && $this->enableJavaScript)
			$this->combineScriptFiles(self::POS_HEAD);

		parent::renderHead($output);
	}

	/**
	 * Inserts the scripts at the beginning of the body section.
	 * @param string the output to be inserted with scripts.
	 */
	public function renderBodyBegin(&$output)
	{
		// $this->enableJavascript has been checked in parent::render()
		if ($this->combineScriptFiles)
			$this->combineScriptFiles(self::POS_BEGIN);

		parent::renderBodyBegin($output);
	}

	/**
	 * Inserts the scripts at the end of the body section.
	 * @param string the output to be inserted with scripts.
	 */
	public function renderBodyEnd(&$output)
	{
		// $this->enableJavascript has been checked in parent::render()
		if ($this->combineScriptFiles)
			$this->combineScriptFiles(self::POS_END);

		parent::renderBodyEnd($output);
	}

	/**
	 * Combine the CSS files, if cached enabled then cache the result so we won't have to do that
	 * Every time
	 */
	protected function combineCssFiles()
	{
		// Check the need for combination
		if (count($this->cssFiles) < 2)
			return;

		$cssFiles = array();
		$toBeCombined = array();
		foreach ($this->cssFiles as $url => $media)
		{
			$file = $this->getLocalPath($url);
			if ($file === false)
				$cssFiles[$url] = $media;
			else
			{
				$media = strtolower($media);
				if ($media === '')
					$media = 'all';
				if (!isset($toBeCombined[$media]))
					$toBeCombined[$media] = array();
				$toBeCombined[$media][$url] = $file;
			}
		}

		foreach ($toBeCombined as $media => $files)
		{
			if ($media === 'all')
				$media = '';

			if (count($files) === 1)
				$url = key($files);
			else
			{
				// get unique combined filename
				$fname = $this->getCombinedFileName($this->cssFileName, $files, $media);
				$fpath = Yii::app()->assetManager->basePath . DIRECTORY_SEPARATOR . $fname;
				// check exists file
				if ($valid = file_exists($fpath))
				{
					$mtime = filemtime($fpath);
					foreach ($files as $file)
					{
						if ($mtime < filemtime($file))
						{
							$valid = false;
							break;
						}
					}
				}
				// re-generate the file
				if (!$valid)
				{
					$urlRegex = '#url\s*\(\s*([\'"])?(?!/|http://)([^\'"\s])#i';
					$fileBuffer = '';
					foreach ($files as $url => $file)
					{
						$contents = file_get_contents($file);
						if ($contents)
						{
							// Reset relative url() in css file
							if (preg_match($urlRegex, $contents))
							{
								$reurl = $this->getRelativeUrl(Yii::app()->assetManager->baseUrl, dirname($url));
								$contents = preg_replace($urlRegex, 'url(${1}' . $reurl . '/${2}', $contents);
							}
							// Append the contents to the fileBuffer
							$fileBuffer .= "/*** CSS File: {$url}";
							if ($this->optimizeCssFiles 
								&& strpos($file, '.min.') === false && strpos($file, '.pack.') === false)
							{
								$fileBuffer .= ", Original size: " . number_format(strlen($contents)) . ", Compressed size: ";
								$contents = $this->optimizeCssCode($contents);
								$fileBuffer .= number_format(strlen($contents));
							}
							$fileBuffer .= " ***/\n";
							$fileBuffer .= $contents . "\n\n";
						}
					}
					file_put_contents($fpath, $fileBuffer);
				}
				// real url of combined file
				$url = Yii::app()->assetManager->baseUrl . '/' . $fname . '?' . filemtime($fpath);
			}
			$cssFiles[$url] = $media;
		}
		// use new cssFiles list replace old ones
		$this->cssFiles = $cssFiles;
	}

	/**
	 * Combine script files, we combine them based on their position, each is combined in a separate file
	 * to load the required data in the required location.
	 * @param $type CClientScript the type of script files currently combined
	 */
	protected function combineScriptFiles($type = self::POS_HEAD)
	{
		// Check the need for combination
		if (!isset($this->scriptFiles[$type]) || count($this->scriptFiles[$type]) < 2)
			return;

		$scriptFiles = array();
		$toBeCombined = array();
		foreach ($this->scriptFiles[$type] as $url)
		{
			$file = $this->getLocalPath($url);
			if ($file === false)
				$scriptFiles[$url] = $url;
			else
				$toBeCombined[$url] = $file;
		}

		if (count($toBeCombined) === 1)
		{
			$url = key($toBeCombined);
			$scriptFiles[$url] = $url;
		}
		else if (count($toBeCombined) > 1)
		{
			// get unique combined filename
			$fname = $this->getCombinedFileName($this->scriptFileName, array_values($toBeCombined), $type);
			$fpath = Yii::app()->assetManager->basePath . DIRECTORY_SEPARATOR . $fname;
			// check exists file
			if ($valid = file_exists($fpath))
			{
				$mtime = filemtime($fpath);
				foreach ($toBeCombined as $file)
				{
					if ($mtime < filemtime($file))
					{
						$valid = false;
						break;
					}
				}
			}
			// re-generate the file
			if (!$valid)
			{
				$fileBuffer = '';
				foreach ($toBeCombined as $url => $file)
				{
					$contents = file_get_contents($file);
					if ($contents)
					{
						// Append the contents to the fileBuffer
						$fileBuffer .= "/*** Script File: {$url}";
						if ($this->optimizeScriptFiles
							&& strpos($file, '.min.') === false && strpos($file, '.pack.') === false)
						{
							$fileBuffer .= ", Original size: " . number_format(strlen($contents)) . ", Compressed size: ";
							$contents = $this->optimizeScriptCode($contents);
							$fileBuffer .= number_format(strlen($contents));
						}
						$fileBuffer .= " ***/\n";
						$fileBuffer .= $contents . "\n\n";
					}
				}
				file_put_contents($fpath, $fileBuffer);
			}
			// add the combined file into scriptFiles
			$url = Yii::app()->assetManager->baseUrl . '/' . $fname . '?' . filemtime($fpath);;
			$scriptFiles[$url] = $url;
		}
		// use new scriptFiles list replace old ones
		$this->scriptFiles[$type] = $scriptFiles;
	}

	/**
	 * Get realpath of published file via its url, refer to {link: CAssetManager}
	 * @return string local file path for this script or css url
	 */
	private function getLocalPath($url)
	{
		$basePath = dirname(Yii::app()->request->scriptFile) . DIRECTORY_SEPARATOR;
		$baseUrl = Yii::app()->request->baseUrl . '/';
		if (!strncmp($url, $baseUrl, strlen($baseUrl)))
		{
			$url = $basePath . substr($url, strlen($baseUrl));
			return $url;
		}
		return false;
	}

	/**
	 * Calculate the relative url
	 * @param string $from source url, begin with slash and not end width slash.
	 * @param string $to dest url
	 * @return string result relative url
	 */
	private function getRelativeUrl($from, $to)
	{
		$relative = '';
		while (true)
		{
			if ($from === $to)
				return $relative;
			else if ($from === dirname($from))
				return $relative . substr($to, 1);
			if (!strncmp($from . '/', $to, strlen($from) + 1))
				return $relative . substr($to, strlen($from) + 1);

			$from = dirname($from);
			$relative .= '../';
		}
	}

	/**
	 * Get unique filename for combined files
	 * @param string $name default filename
	 * @param array $files files to be combined
	 * @param string $type css media or script position
	 * @return string unique filename
	 */
	private function getCombinedFileName($name, $files, $type = '')
	{
		$pos = strrpos($name, '.');
		if (!$pos)
			$pos = strlen($pos);
		$hash = sprintf('%x', crc32(implode('+', $files)));

		$ret = substr($name, 0, $pos);
		if ($type !== '')
			$ret .= '-' . $type;
		$ret .= '-' . $hash . substr($name, $pos);

		return $ret;
	}

	/**
	 * Optmize css, strip any spaces and newline
	 * @param string $data input css data
	 * @return string optmized css data
	 */
	private function optimizeCssCode($code)
	{
		require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'CssMin.php';
		return CssMin::minify($code, array(), array('CompressUnitValues' => true));
	}

	/**
	 * Optimize script via google compiler
	 * @param string $data script code
	 * @return string optimized script code
	 */
	private function optimizeScriptCode($code)
	{
		require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'JSMinPlus.php';
		$minified = JSMinPlus::minify($code);
		return ($minified === false ? $code : $minified);
	}
}
