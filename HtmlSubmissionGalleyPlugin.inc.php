<?php

/**
 * @file plugins/generic/htmlSubmissionGalley/HtmlSubmissionGalleyPlugin.inc.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class HtmlSubmissionGalleyPlugin
 * @ingroup plugins_generic_htmlSubmissionGalley
 *
 * @brief Class for HtmlSubmissionGalley plugin
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class HtmlSubmissionGalleyPlugin extends GenericPlugin {
	/**
	 * @see Plugin::register()
	 */
	function register($category, $path, $mainContextId = null) {
		if (!parent::register($category, $path, $mainContextId)) return false;
		if ($this->getEnabled($mainContextId)) {
			HookRegistry::register('ArticleHandler::view::galley', array($this, 'submissionViewCallback'), HOOK_SEQUENCE_LATE);
			HookRegistry::register('ArticleHandler::download', array($this, 'submissionDownloadCallback'), HOOK_SEQUENCE_LATE);
			HookRegistry::register('PreprintHandler::view::galley', array($this, 'submissionViewCallback'), HOOK_SEQUENCE_LATE);
			HookRegistry::register('PreprintHandler::download', array($this, 'submissionDownloadCallback'), HOOK_SEQUENCE_LATE);
		}
		return true;
	}

	/**
	 * Install default settings on context creation.
	 * @return string
	 */
	function getContextSpecificPluginSettingsFile() {
		return $this->getPluginPath() . '/settings.xml';
	}

	/**
	 * Get the display name of this plugin.
	 * @return String
	 */
	function getDisplayName() {
		return __('plugins.generic.htmlSubmissionGalley.displayName');
	}

	/**
	 * Get a description of the plugin.
	 */
	function getDescription() {
		return __('plugins.generic.htmlSubmissionGalley.description');
	}

	/**
	 * Present the submission wrapper page.
	 * @param string $hookName
	 * @param array $args
	 */
	function submissionViewCallback($hookName, $args) {
		$application = Application::get();
		$applicationName = $application->getName();
		$request =& $args[0];

		if ($applicationName == "ojs2"){
			$issue =& $args[1];
			$galley =& $args[2];
			$submission =& $args[3];
			$page = 'article';
		}
		if ($applicationName == "ops"){
			$galley =& $args[1];
			$submission =& $args[2];
			$page = 'preprint';
		}	

		if (!$galley) {
			return false;
		}

		$submissionFile = $galley->getFile();
		$filepath = Services::get('file')->getPath($submissionFile->getData('fileId'));
		if (Services::get('file')->fs->getMimetype($filepath) === 'text/html') {
			foreach ($submission->getData('publications') as $publication) {
				if ($publication->getId() === $galley->getData('publicationId')) {
					$galleyPublication = $publication;
					break;
				}
			}
			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->assign(array(
				'page' => $page,
				'submission' => $submission,
				'galley' => $galley,
				'isLatestPublication' => $submission->getData('currentPublicationId') === $galley->getData('publicationId'),
				'galleyPublication' => $galleyPublication,
			));

			if ($applicationName == "ojs2"){
				$templateMgr->assign(array(
					'issue' => $issue,
				));
			}

			$templateMgr->display($this->getTemplateResource('display.tpl'));

			return true;
		}

		return false;
	}

	/**
	 * Present rewritten submission HTML.
	 * @param string $hookName
	 * @param array $args
	 */
	function submissionDownloadCallback($hookName, $args) {
		$submission =& $args[0];
		$galley =& $args[1];
		$fileId =& $args[2];
		$request = Application::get()->getRequest();

		if (!$galley) {
			return false;
		}

		$submissionFile = $galley->getFile();
		$filepath = Services::get('file')->getPath($submissionFile->getData('fileId'));
		if (Services::get('file')->fs->getMimetype($filepath) === 'text/html' && $galley->getData('submissionFileId') == $fileId) {
			if (!HookRegistry::call('HtmlArticleGalleyPlugin::articleDownload', array($submission,  &$galley, &$fileId))) {
				echo $this->_getHTMLContents($request, $galley);
				$returner = true;
				HookRegistry::call('HtmlArticleGalleyPlugin::articleDownloadFinished', array(&$returner));
			}
			return true;
		}

		return false;
	}

	/**
	 * Return string containing the contents of the HTML file.
	 * This function performs any necessary filtering, like image URL replacement.
	 * @param $request PKPRequest
	 * @param $galley SubmissionGalley
	 * @return string
	 */
	protected function _getHTMLContents($request, $galley) {
		$application = Application::get();
		$applicationName = $application->getName();
		$submissionFile = $galley->getFile();
		$submissionId = $submissionFile->getData('submissionId');
		$contents = Services::get('file')->fs->read(Services::get('file')->getPath($submissionFile->getData('fileId')));

		// Replace media file references
		import('lib.pkp.classes.submission.SubmissionFile'); // Constants
		$embeddableFilesIterator = Services::get('submissionFile')->getMany([
			'assocTypes' => [ASSOC_TYPE_SUBMISSION_FILE],
			'assocIds' => [$submissionFile->getId()],
			'fileStages' => [SUBMISSION_FILE_DEPENDENT],
			'includeDependentFiles' => true,
		]);
		$embeddableFiles = iterator_to_array($embeddableFilesIterator);

		foreach ($embeddableFiles as $embeddableFile) {
			$params = array();

			if ($embeddableFile->getFileType()=='text/plain' || $embeddableFile->getFileType()=='text/css') $params['inline']='true';

			// Ensure that the $referredSubmission object refers to the submission we want
			if (!$referredSubmission || $referredSubmission->getId() != $submissionId) {
				$referredSubmission = $submissionDao->getById($submissionId);
			}
			$fileUrl = $request->url(null, 'article', 'download', array($referredSubmission->getBestId(), $galley->getBestGalleyId(), $embeddableFile->getId()), $params);
			$pattern = preg_quote(rawurlencode($embeddableFile->getLocalizedData('name')));

			$contents = preg_replace(
				'/([Ss][Rr][Cc]|[Hh][Rr][Ee][Ff]|[Dd][Aa][Tt][Aa])\s*=\s*"([^"]*' . $pattern . ')"/',
				'\1="' . $fileUrl . '"',
				$contents
			);
			if ($contents === null) error_log('PREG error in ' . __FILE__ . ' line ' . __LINE__ . ': ' . preg_last_error());

			// Replacement for Flowplayer
			$contents = preg_replace(
				'/[Uu][Rr][Ll]\s*\:\s*\'(' . $pattern . ')\'/',
				'url:\'' . $fileUrl . '\'',
				$contents
			);
			if ($contents === null) error_log('PREG error in ' . __FILE__ . ' line ' . __LINE__ . ': ' . preg_last_error());

			// Replacement for other players (ested with odeo; yahoo and google player won't work w/ OJS URLs, might work for others)
			$contents = preg_replace(
				'/[Uu][Rr][Ll]=([^"]*' . $pattern . ')/',
				'url=' . $fileUrl ,
				$contents
			);
			if ($contents === null) error_log('PREG error in ' . __FILE__ . ' line ' . __LINE__ . ': ' . preg_last_error());
		}

		// Perform replacement for ojs://... URLs
		$contents = preg_replace_callback(
			'/(<[^<>]*")[Oo][Jj][Ss]:\/\/([^"]+)("[^<>]*>)/',
			array($this, '_handleAppUrl'),
			$contents
		);
		if ($contents === null) error_log('PREG error in ' . __FILE__ . ' line ' . __LINE__ . ': ' . preg_last_error());

		$templateMgr = TemplateManager::getManager($request);
		$contents = $templateMgr->loadHtmlGalleyStyles($contents, $embeddableFiles);

		// Perform variable replacement for context, issue, site info

		$context = $request->getContext();
		$site = $request->getSite();

		$paramArray = array(
			'contextTitle' => $context->getLocalizedName(),
			'siteTitle' => $site->getLocalizedTitle(),
			'currentUrl' => $request->getRequestUrl()
		);

		if ($applicationName == "ojs2"){
			$issueDao = DAORegistry::getDAO('IssueDAO'); /* @var $issueDao IssueDAO */
			$issue = $issueDao->getBySubmissionId($submissionId);
			$paramArray['issueTitle'] = $issue?$issue->getIssueIdentification():__('editor.article.scheduleForPublication.toBeAssigned');
		}

		foreach ($paramArray as $key => $value) {
			$contents = str_replace('{$' . $key . '}', $value, $contents);
		}

		return $contents;
	}

	function _handleAppUrl($matchArray) {
		$request = Application::get()->getRequest();
		$url = $matchArray[2];
		$anchor = null;
		if (($i = strpos($url, '#')) !== false) {
			$anchor = substr($url, $i+1);
			$url = substr($url, 0, $i);
		}
		$urlParts = explode('/', $url);
		if (isset($urlParts[0])) switch(strtolower_codesafe($urlParts[0])) {
			case 'journal':
				$url = $request->url(
				isset($urlParts[1]) ?
				$urlParts[1] :
				$request->getRequestedJournalPath(),
				null,
				null,
				null,
				null,
				$anchor
				);
				break;
			case 'server':
				$url = $request->url(
				isset($urlParts[1]) ?
				$urlParts[1] :
				$request->getRequestedJournalPath(),
				null,
				null,
				null,
				null,
				$anchor
				);
				break;
			case 'article':
				if (isset($urlParts[1])) {
					$url = $request->url(
							null,
							'article',
							'view',
							$urlParts[1],
							null,
							$anchor
					);
				}
				break;
			case 'preprint':
				if (isset($urlParts[1])) {
					$url = $request->url(
							null,
							'preprint',
							'view',
							$urlParts[1],
							null,
							$anchor
					);
				}
				break;
			case 'issue':
				if (isset($urlParts[1])) {
					$url = $request->url(
							null,
							'issue',
							'view',
							$urlParts[1],
							null,
							$anchor
					);
				} else {
					$url = $request->url(
							null,
							'issue',
							'current',
							null,
							null,
							$anchor
					);
				}
				break;
			case 'sitepublic':
				array_shift($urlParts);
				import ('classes.file.PublicFileManager');
				$publicFileManager = new PublicFileManager();
				$url = $request->getBaseUrl() . '/' . $publicFileManager->getSiteFilesPath() . '/' . implode('/', $urlParts) . ($anchor?'#' . $anchor:'');
				break;
			case 'public':
				array_shift($urlParts);
				$context = $request->getContext();
				import ('classes.file.PublicFileManager');
				$publicFileManager = new PublicFileManager();
				$url = $request->getBaseUrl() . '/' . $publicFileManager->getContextFilesPath($context->getId()) . '/' . implode('/', $urlParts) . ($anchor?'#' . $anchor:'');
				break;
		}
		return $matchArray[1] . $url . $matchArray[3];
	}
}
