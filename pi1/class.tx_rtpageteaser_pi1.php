<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2008 Stefan Voelker <t3x@nyxos.de>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

require_once(PATH_tslib.'class.tslib_pibase.php');
require_once(PATH_t3lib.'class.t3lib_pagetree.php');
if (t3lib_extMgm::isLoaded('dam')) {
	require_once(t3lib_extMgm::extPath('dam') . 'lib/class.tx_dam_media.php');
}

/**
 * Plugin 'Pageteaser' for the 'rt_pageteaser' extension.
 *
 * @author	Stefan Voelker <t3x@nyxos.de>
 * @package	TYPO3
 * @subpackage	tx_rtpageteaser
 */
class tx_rtpageteaser_pi1 extends tslib_pibase {
	var $prefixId      = 'tx_rtpageteaser_pi1';		// Same as class name
	var $scriptRelPath = 'pi1/class.tx_rtpageteaser_pi1.php';	// Path to this script relative to the extension dir.
	var $extKey        = 'rt_pageteaser';	// The extension key.
	var $pi_checkCHash = true;
	
	
	/**
	 * The main method of the PlugIn
	 *
	 * @param	string		$content: The PlugIn content
	 * @param	array		$conf: The PlugIn configuration
	 * @return	The content that is displayed on the website
	 */
	function main($content,$conf)	{
		$this->conf=$conf;
		$this->pi_setPiVarDefaults();
		$this->pi_loadLL();
		$this->pi_initPIflexForm();
		
		$content = '';	

		// Template code
		$templateFromConstants = $this->conf['templateFile'];
		$userTemplate = $this->pi_getFFvalue($this->cObj->data['pi_flexform'], 'templateFile', 'sSelections');
		if($userTemplate == '') {
			$theTemplate = $templateFromConstants;
		} else {
			$theTemplate = $userTemplate;
		}
		# template-fallback
		if(!$theTemplate) {
			$theTemplate = 'EXT:'.$this->extKey.'/res/pageteaser_template.html';
		}
		
		$this->templateCode = $this->cObj->fileResource($theTemplate);
		$templateCode = $this->templateCode;
		
		# add header data
		$subPart = $this->cObj->getSubpart($templateCode, '###HEADER_ADDITIONS###');
		$key = $this->prefixId.'_'.md5($subPart);
		if (!isset($GLOBALS['TSFE']->additionalHeaderData[$key] )) {
			$templateOut = $this->cObj->substituteMarkerArray($subPart, array('###SITE_REL_PATH###' => t3lib_extMgm::siteRelPath($this->extKey),));
			$GLOBALS['TSFE']->additionalHeaderData[$key] = $templateOut;
		}
		
		# check for dam_pages
		$use_dam_pages = (int)$this->conf['use_dam_pages'];
		
		# limit of pages
		$limitFromConstants = $this->conf['limitPages'];
		
		# get limit via Flexform
		$userLimit = $this->pi_getFFvalue($this->cObj->data['pi_flexform'], 'limitPages', 'sSelections');
		if((int)$userLimit == 0) {
			$limit = $limitFromConstants;
		} else {
			$limit = $userLimit;
		}
		
		# which mode should be displayed ?	
		$userMode = $this->pi_getFFvalue($this->cObj->data['pi_flexform'], 'mode', 'sSelections');
		
		switch($userMode) {
			case 'RECORDS':
				# selected pages
				$selectedPids = $this->pi_getFFvalue($this->cObj->data['pi_flexform'], 'pageBrowser', 'sSelections');
				$content .= $this->getSelectedPages($selectedPids, $limit, $use_dam_pages);
				break;
			default:
				# Mode: subpages from selected page
				$masterPid = $this->pi_getFFvalue($this->cObj->data['pi_flexform'], 'pageBrowserSingle', 'sSelections');
				$useKeyword = $this->pi_getFFvalue($this->cObj->data['pi_flexform'], 'keywords', 'sSelections'); 
				$keywordMode = $this->pi_getFFvalue($this->cObj->data['pi_flexform'], 'keywordMode', 'sSelections');
				$orderPages = $this->pi_getFFvalue($this->cObj->data['pi_flexform'], 'orderPages', 'sSelections');
				$sortPages = $this->pi_getFFvalue($this->cObj->data['pi_flexform'], 'sortPages', 'sSelections');
				# how many level of subpages ?
				$userLevel = (int)$this->pi_getFFvalue($this->cObj->data['pi_flexform'], 'levelSubpages', 'sSelections');
				$content .= $this->getSubpages($masterPid, $limit, $useKeyword, $keywordMode, $orderPages, $sortPages, $use_dam_pages, $userLevel);
		}
	
		return $this->pi_wrapInBaseClass($content);
	}
	
	###############################################
	#
	# gets all subpages from selected pid, and calls output-function
	#

	function getSubpages($masterPid, $limit, $useKeyword, $keywordMode, $orderPages, $sortPages, $use_dam_pages, $userLevel) {
		$cont = '';
		$language = $GLOBALS['TSFE']->sys_language_uid;

		# get list of pages with this pid
		
		# create a list of tree-pid's, when $userLevel > 0
		if ($userLevel > 0 ) {
			// Initialize starting point of page tree:
			$treeStartingPoint = $masterPid;
			$treeStartingRecord = $this->pi_getRecord('pages', $treeStartingPoint);
			$depth = $userLevel;		
			// Initialize tree object:
			$tree = t3lib_div::makeInstance('t3lib_pageTree');
			$tree->init($this->cObj->enableFields('pages'));
			$tree->tree[] = array( 'row' => $treeStartingRecord );
			// Create the tree from starting point:
			$tree->getTree($treeStartingPoint, $depth, '');
#echo t3lib_div::debug($tree->tree,'');
			$treePidArray = array();
			foreach($tree->tree as $singlePage ) {
				$treePidArray[] = $singlePage['row']['uid'];
			}
			$treePidList = implode(',',$treePidArray);
		} else {
			$treePidList = $masterPid;
		}
		
#echo t3lib_div::debug($tree->tree,'Baum');

		# check, if user wants to see sub-subpages, and if sorting is pagetree
		if($userLevel > 0 && $orderPages == 'NORMAL') {
			$cont = $this->getTreePageteaser($treePidArray, $limit, $useKeyword, $keywordMode, $orderPages, $sortPages, $use_dam_pages);
		} else {
			// ok, user wants to control everything...
			# if $useKeyword is not empty, use it as additional clause
			if($useKeyword != '' && $useKeyword != 1 && ($keywordMode == 'AND' || $keywordMode == 'OR' || $keywordMode == 'NOT') ) {
				
				$addWhereClause = ' ';
				$useKeyword = strtolower(str_replace(' ', '', $useKeyword));
				$keywords = explode(',', $useKeyword);
				$numKeywords = count($keywords);
				
				switch ($keywordMode) {
					case 'OR':
						for ($i = 0; $i < $numKeywords; $i++) {
							if ($i == 0 ) {
								$addWhereClause = ' AND (';
							}
							if($i == $numKeywords-1) {
								$addWhereClause .= 'keywords LIKE "%'.$keywords[$i].'%" )';
							} else {
								$addWhereClause .= 'keywords LIKE "%'.$keywords[$i].'%" OR ';
							}
						}
						break;
					case 'AND';
						for ($i = 0; $i < $numKeywords; $i++) {
							if ($i == 0) {
								$addWhereClause = ' AND ';
							}
							if($i == $numKeywords-1) {
								$addWhereClause .= 'keywords LIKE "%'.$keywords[$i].'%"';
							} else {
								$addWhereClause .= 'keywords LIKE "%'.$keywords[$i].'%" AND ';
							}
						}
						break;
					case 'NOT':
						for ($i = 0; $i < $numKeywords; $i++) {
							if ($i == 0) {
								$addWhereClause = ' AND (';
							}
							if($i == $numKeywords-1) {
								$addWhereClause .= 'NOT keywords LIKE "%'.$keywords[$i].'%" )';
							} else {
								$addWhereClause .= 'NOT keywords LIKE "%'.$keywords[$i].'%" OR ';
							}
						}
						break;
					default:
				}		
			}
			
				# ordering
				
				$orderBy = '';
				switch ($orderPages) {
					case 'NORMAL':
							$orderBy .= ' pages.sorting';
						break;
					case 'TITLE':
						$orderBy .= ' pages.title';
						break;
					case 'TSTAMP':
						$orderBy .= ' pages.tstamp';
						break;
					case 'CRDATE':
						$orderBy .= ' pages.crdate';
						break;
					case 'RANDOM':
						$orderBy .= ' RAND()';
						break;
					default:
				}
				
				# sorting
				if($orderPages != 'RANDOM') {
				switch ($sortPages) {
					case 'ASC':
						$orderBy .= ' ASC';
						break;
					case 'DESC':
						$orderBy .= ' DESC';
						break;
					default:
				}
				}
			
			#
			# check ignoreMode for pages without text in the abstract
			#
					
			$ignoreMode = 0;
			$ignoreMode = $this->conf['ignorePagesWithoutAbstract'];
			if($ignoreMode == 1) {
				$addWhereClause .= ' AND abstract != "" ';
			}
			
			#
			# check ignoreMode for pages without image
			#
			
			$ignoreModeImage = 0;
			$ignoreModeImage = $this->conf['ignorePagesWithoutImage'];	
			if($ignoreModeImage == 1) {
				# check, if dam_pages is loaded
				if (t3lib_extMgm::isloaded('dam_pages') && $use_dam_pages == 1 ) {
					# yes, dam_pages is loaded
					$addWhereClause .= ' AND tx_dampages_files > 0 ';
				} else {
					# normal media field
					$addWhereClause .= ' AND ( media != "" AND media != "NULL" ) ';
				}
			}
			# language
			
			
			$subpages = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
					'uid, title, abstract, media, keywords, tstamp, crdate, sorting',
					'pages',
					'pid IN ('.$treePidList.') '.$addWhereClause.$this->cObj->enableFields('pages'),
					'',# group by
					$orderBy,
				$limit);
			$counter = 0;
			while ($pageData = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($subpages) ) {
	#echo t3lib_div::debug($pageData,'');	
				$cont .= $this->outputTeaser($pageData, $counter, $use_dam_pages);
				$counter ++;
			}		
			$GLOBALS['TYPO3_DB']->sql_free_result($subpages);	
			if($counter == 0) {
				$cont = $this->pi_getLL('noresult');
			}		
		}
		

		
		return $cont;
	}
	
	###############################################
	#
	# gets data from all user-selected pids and calls output-Function
	#
	
	function getSelectedPages($selectedPids, $limit, $use_dam_pages) {
		$cont = '';
		$selectedPages = explode( ',', $selectedPids);
		$numPages = count($selectedPages);
		$counter = 0;
		for($i = 0; $i < $numPages; $i++) {
			// language
			$language = $GLOBALS['TSFE']->sys_language_uid;
			if ($language > 0) {
				// get language
				$pageRes = $GLOBALS['TYPO3_DB']->exec_SELECTquery('*','pages_language_overlay','pid = '.$selectedPages[$i].' AND sys_language_uid = '.$language);
				$page = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($pageRes);
			} else {
				// standard language
				$page = $this->pi_getRecord('pages',$selectedPages[$i]);
			}
			// if there is no content of page properties, ignore page
			if (is_array($page) && (int)$page['uid'] > 0) {
				$cont .= $this->outputTeaser($page, $counter, $use_dam_pages);
				$counter ++;
			}
		}
		return $cont;
	}
	
	###############################################
	#
	# returns output-data for one page
	#
	
	function outputTeaser($pageData, $counter, $use_dam_pages) {
		$cont = '';
		$imagePath = 'uploads/media/';
		$pageUid = $pageData['uid'];
#echo t3lib_div::debug($pageData,'pageData');
		# cropping
		$userCrop = $this->pi_getFFvalue($this->cObj->data['pi_flexform'], 'crop', 'sContent');
		if ($userCrop && (int)$userCrop > 1) {
			$cropping = (int)$userCrop;
		}
		
		# Get main template
		$template = $this->cObj->getSubpart($this->templateCode, '###MAIN###');
		# markers
		$markers = array();
		# our cObj
		$cObj = t3lib_div::makeInstance('tslib_cObj');
		$cObj->start($pageData, 'pages');

		if($counter %2 == 1 ? $divClass = 'even': $divClass = 'odd');
		
		$markers['###oddeven###'] = $divClass;
		
		#
		# the Image
		#
		$img = Array();
		$img = $this->conf['singleView.']['image.'];
		
		$userMaxWidth = $this->pi_getFFvalue($this->cObj->data['pi_flexform'], 'maxWidth', 'sSelections');;
		$userMaxHeight = $this->pi_getFFvalue($this->cObj->data['pi_flexform'], 'maxHeight', 'sSelections');;
		
		if((int)$userMaxWidth > 1) {
			$img['file.']['maxW'] = (int)$userMaxWidth.'m';
		}
		if ((int)$userMaxHeight > 1) {
			$img['file.']['maxH'] = (int)$userMaxHeight.'m';
			
		}

		# check, if dam_pages is installed
		if (t3lib_extMgm::isloaded('dam_pages') && $use_dam_pages == 1 ) {
			# use DAM field
			
			$useTable = 'pages';
			// if a language is selected, we need to use another table
			$language = $GLOBALS['TSFE']->sys_language_uid;
			if ((int)$language > 0 ) {
				$useTable = 'pages_language_overlay';
			}
			$damPics = tx_dam_db::getReferencedFiles($useTable, $pageUid,'tx_dampages_files', 'tx_dam_mm_ref');
			// fallback for translated images
			$imageFallBack = $this->conf['getImageFromStandardPage'];
			if ( $imageFallBack == 1 && empty($damPics['files']) && $language > 0 ) {
				$pageUid = $pageData['pid'];
				$damPics = tx_dam_db::getReferencedFiles('pages', $pageUid, 'tx_dampages_files', 'tx_dam_mm_ref');		
			}
			list($uidDam, $filePath) = each($damPics['files']);
			
			$mediaClass = tx_div::makeInstanceClassName('tx_dam_media');
			$media = new $mediaClass($filePath);
			# Check DAM-Version
		 	if(method_exists($media, 'fetchFullMetaData')) {
		 		$media->fetchFullMetaData();
		 	} else {
		 		$media->fetchFullIndex();
		 	}
#echo t3lib_div::debug($media->meta);
		     if( (int)$media->meta['uid'] > 0 ) {
		     	$img['file'] = $media->meta['file_path'].$media->meta['file_name'];
		     	$markers['###IMAGE###'] = $this->cObj->IMAGE($img);
		     } else {
		     	$markers['###IMAGE###'] = '';
		     }
			
		} else {
			# use original media field		
			if ($pageData['media'] != '' ) {
				$img['file'] = $imagePath.$pageData['media'];
				$teaserPicture = $this->cObj->IMAGE($img);
				$markers['###IMAGE###'] = $teaserPicture;
			} else {
				$markers['###IMAGE###'] = '';
			}	
		}
		
		// linked title
		
		$pageId = $pageData['uid'];
		$linkConf = array(
		  // Link to current page
		  'parameter' => $pageId,
		  // We must add cHash because we use parameters
		  'useCashHash' => true,
		  // We want link only
		  'returnLast' => 'url',
		);
		$language = (int)t3lib_div::GPvar('L');
		if ($language > 0) {
			// our data set is from pages_language_overlay, so we need to jump to pid
			$linkConf['parameter'] = $pageData['pid'];
		}
		$url = $this->cObj->typoLink('', $linkConf);
		
		$markers['###TITLE###'] = '<a href="'.$url.'">'.$pageData['title'].'</a>';
		
		// teaser text
		
		$teaserText = $cObj->stdWrap(htmlspecialchars($pageData['abstract']), $this->conf['singleView.']['abstract_stdWrap.']);
		
		# check for cropping
		if ($cropping > 1) {
			$wrapobj = array ('stripHtml' => '1');
			# specifies the chars after cropped text
			$cropAdd = $this->pi_getLL('cropAdd');
			if (!$cropAdd || $cropAdd == '') {
				$cropAdd = '...';
			}
			
			# crop only after words
			$cropOnlyAfterWords = $this->conf['cropOnlyAfterWords'];
			if ((int)$cropOnlyAfterWords > 0) {			
				$cropOnlyAfterWords = 1;
			} else {
				$cropOnlyAfterWords = 0;
			}
			
			# build the cropped text
			$cropVar = $cropping.'|'.$cropAdd.'|'.$cropOnlyAfterWords;
			$croppedTeaserText = $this->cObj->crop($this->cObj->stdWrap($teaserText,$wrapobj), $cropVar);
			$markers['###ABSTRACT###'] = $croppedTeaserText;
		} else {
			# use uncropped text
			$markers['###ABSTRACT###'] = $teaserText;
		}
		
		$markers['###TSTAMP###'] = $cObj->stdWrap($pageData['tstamp'], $this->conf['singleView.']['tstamp_stdWrap.']);
		
		if($pageData['navtitle'] == '') {
			$markers['###NAVTITLE###'] = '';
		} else {
			$markers['###NAVTITLE###'] = $pageData['navtitle'];
		}
		
		$markers['###CRDATE###'] = $cObj->stdWrap($pageData['crdate'], $this->conf['singleView.']['crdate_stdWrap.']);
		
		# 2 be done: age
		$markers['###AGE###'] = '';
		
		if($pageData['keywords'] == '') {
			$markers['###KEYWORDS###'] = '';
		} else {
			$markers['###KEYWORDS###'] = $cObj->stdWrap($pageData['keywords'], $this->conf['singleView.']['keywords_stdWrap.']);
		}
		
		$markers['###MORELINK###'] = '<a href="'.$url.'">'.$this->pi_getLL('moreLink').'</a>';
		
		$cont = $this->cObj->substituteMarkerArray($template, $markers);
		
		return $cont;
	}
	
	/**
	 * getTreePageteaser function.
	 * 
	 * @access protected
	 * @param mixed $treePidArray contains an array with all pages (and subpages) and the page-data
	 * @param mixed $limit
	 * @param mixed $useKeyword
	 * @param mixed $keywordMode
	 * @param mixed $orderPages
	 * @param mixed $sortPages
	 * @param mixed $use_dam_pages
	 * @return void
	 */
	protected function getTreePageteaser($treePidArray, $limit, $useKeyword, $keywordMode, $orderPages, $sortPages, $use_dam_pages) {
		$output = '';
		$counter = 0;
		$language = $GLOBALS['TSFE']->sys_language_uid;
#echo t3lib_div::debug($treePidArray,'');		
		# we don't need the first page here
		$mountPoint = array_shift($treePidArray);
		
		#
		if ($sortPages == 'DESC') {
			$treePidArray = array_reverse($treePidArray, TRUE);
		}
#echo t3lib_div::debug($limit,'limit');
		
		foreach($treePidArray as $eachPage) {
			$useThisEntry = 1;
			
			// standard language
			$pageData = $this->pi_getRecord('pages',$eachPage);
			
			// language data
			if ($language > 0) {
				$languagePageRes = $GLOBALS['TYPO3_DB']->exec_SELECTquery('*','pages_language_overlay','pid = '.$pageData['uid'].' AND sys_language_uid = '.$language);
				$languagePage = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($languagePageRes);
				$theKeywords = $languagePage['keywords'];
				$theAbstract = $languagePage['abstract'];
				$theDamFiles = $languagePage['tx_dampages_files'];
				$theMediaFiles = $theDamFiles['media'];
			} else {
				$theKeywords = $pageData['keywords'];
				$theAbstract = $pageData['abstract'];
				$theDamFiles = $pageData['tx_dampages_files'];
				$theMediaFiles = $pageData['media'];
			}
			
#echo t3lib_div::debug($eachPage,'');
			
			# ignore empty abstracts ?
			$ignoreMode = 0;
			$ignoreMode = $this->conf['ignorePagesWithoutAbstract'];
			if($ignoreMode == 1 && $theAbstract == '' ) {
				$useThisEntry = 0;
			}
			
			# ignore images ?
			$ignoreModeImage = 0;
			$ignoreModeImage = $this->conf['ignorePagesWithoutImage'];	
			if($ignoreModeImage == 1) {
				# check, if dam_pages is loaded
				if (t3lib_extMgm::isloaded('dam_pages') && $use_dam_pages == 1 && $theDamFiles <= 0 ) {
					# yes, dam_pages is loaded
					$useThisEntry = 0;
				} else {
					if ($theMediaFiles == '' || $theMediaFiles == 'NULL' )
					# normal media field
					$useThisEntry = 0;
				}
			}
			
			# keywords
			if ($useKeyword != '' && $useKeyword != 1 && ($keywordMode == 'AND' || $keywordMode == 'OR' || $keywordMode == 'NOT')) 	{
				
				$useKeyword = strtolower(str_replace(' ', '', $useKeyword));
				$keywords = explode(',', $useKeyword);
				$numKeywords = count($keywords);
				$useThisEntry = 0;
								
				switch ($keywordMode) {
					case 'OR':
						for ($i = 0; $i < $numKeywords; $i++) {
							$query = '/'.$keywords[$i].'/';			
							if (preg_match($query, $theKeywords) == 1) {
								$useThisEntry = 1;
							}
						}
						break;
					case 'AND';
						$hits = 0;
						for ($i = 0; $i < $numKeywords; $i++) {
							$query = '/'.$keywords[$i].'/';
							$horst = preg_match($query, $theKeywords);
							if (preg_match($query, $theKeywords) == 1) {
								$hits+=1;
							}
						}
						if ($hits == $numKeywords) {
							$useThisEntry = 1;
						}
						break;
					case 'NOT':
						for ($i = 0; $i < $numKeywords; $i++) {
							$query = '/'.$keywords[$i].'/';
							if (preg_match($query, $theKeywords) != 1) {
								$useThisEntry = 1;
							}
						}
						break;
					default:
				}
			}			

			if ($useThisEntry == 1 && $counter < $limit) {
				// if language is selected
				// only output this entry, if there is a translated version
				if ($language > 0 ) {
					// we need language X	
					$output .= $this->outputTeaser($languagePage, $counter, $use_dam_pages);
					$counter++;
				} else {
					// standard language: output EVERYTHING ;)
					$output .= $this->outputTeaser($pageData, $counter, $use_dam_pages);
					$counter++;
				}
			}			
		}
		
		return $output;
	}
}



if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/rt_pageteaser/pi1/class.tx_rtpageteaser_pi1.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/rt_pageteaser/pi1/class.tx_rtpageteaser_pi1.php']);
}

?>