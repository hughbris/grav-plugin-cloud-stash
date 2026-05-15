<?php
namespace Grav\Plugin;

require_once __DIR__ . '/vendor/autoload.php';

use Grav\Common\Plugin;
use \Grav\Common\Utils;
use RocketTheme\Toolbox\Event\Event;
use RocketTheme\Toolbox\File\File;

/**
 * Class CloudStashPlugin
 * @package Grav\Plugin
 */
class CloudStashPlugin extends Plugin {
	/**
	 * @return array
	 *
	 * The getSubscribedEvents() gives the core a list of events
	 *     that the plugin wants to listen to. The key of each
	 *     array section is the event that the plugin listens to
	 *     and the value (in the form of an array) contains the
	 *     callable (or function) as well as the priority. The
	 *     higher the number the higher the priority.
	 */
	public static function getSubscribedEvents() {
		return [
			'onPluginsInitialized' => ['onPluginsInitialized', 0]
		];
	}

	/**
	 * Initialize the plugin
	 */
	public function onPluginsInitialized() {
		// Don't proceed if we are in the admin plugin
		if ($this->isAdmin()) {
			return;
		}

		// Enable the main event we are interested in
		$this->enable([
			'onFormProcessed' => ['customFormActions', 0]
		]);
	}

	/**
	 * [customFormActions] Process custom form actions defined in this plugin:
	 *
	 * - stash_pdf: stash a PDF in the cloud
	 *
	 * @param Event $event
	 * @throws \RuntimeException
	 */
	public function customFormActions(Event $event)	{
		$form = $event['form'];
		$action = $event['action'];

		// stash custom action
		switch ($action) {
			case 'stash':
				$this->saveToStash($event);
				break;
		}
		// stash PDF custom action
		switch ($action) {
			case 'stash_pdf':
				$this->saveToStash($event, TRUE);
				break;
		}
	}

	/**
	 * Save PDF formatted data into a cloud stash
	 *
	 * @param Event $event
	 */
	public function saveToStash(Event $event, $asPDF=FALSE) {

		$form = $event['form'];
		$params = $event['params'];

		$prefix = array_key_exists('fileprefix', $params) ? $params['fileprefix'] : '';
		$format = array_key_exists('dateformat', $params) ? $params['dateformat'] : 'Ymd-His-u';
		$postfix = array_key_exists('filepostfix', $params) ? $params['filepostfix'] : '';
		$ext = $asPDF ? '.pdf' : (array_key_exists('extension', $params) ? $params['extension'] : '.txt');

		if (array_key_exists('dateraw', $params) AND (bool) $params['dateraw']) {
			$datestamp = date($format);
		}
		else {
			$utimestamp = microtime(true);
			$timestamp = floor($utimestamp);
			$milliseconds = round(($utimestamp - $timestamp) * 1000000);
			$datestamp = date(preg_replace('`(?<!\\\\)u`', \sprintf('%06d', $milliseconds), $format), $timestamp);
		}
		$filename = array_key_exists('filename', $params) ? $params['filename'] : prefix . $datestamp . $postfix . $ext;

		$stash_attachments = array_key_exists('add_uploads', $params) ? $params['add_uploads'] : [];

		$twig = $this->grav['twig'];
		$vars = [
			'form' => $form,
		];
		$twig->itemData = $form->getData(); // FIXME for default data.html template below - might work OK
		$filename = $twig->processString($filename, $vars);
		$content_type = Utils::getMimeByFilename($filename, NULL);

		$foldername = array_key_exists('foldername', $params) ? $params['foldername'] : pathinfo($filename,  PATHINFO_FILENAME) /* TODO: not tested for filenames specified with filename parameter */;
		$foldername = $twig->processString($foldername, $vars);

		$contents = $twig->processString(array_key_exists('body', $params) ? $params['body'] : '{% include "forms/data.html.twig" %}', $vars);

		$metadata = $this->extractMetadata($form->getData());
		$stash_options = [];

		if ($asPDF) {
			$snappy = new SnappyManager($this->grav);
			$contents = $snappy->servePDF([$contents], $metadata);
			$stash_options['ContentType'] = 'application/pdf';
		}
		elseif(!is_null($content_type)) {
			$stash_options['ContentType'] = $content_type;
		}

		$stash = array_key_exists('stash', $params) ? $params['stash'] : $params['provider']; // support deprecated property
		$stash_config = $this->config->plugins['cloud-stash']['stashes'][$stash];

		$bucket = array_key_exists('bucket', $params) ? $params['bucket'] : $stash_config['defaults']['target']; // TODO: raise an exception here if neither of these are provided

		$client = new CloudStash\S3Provider($stash);

		// see https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-s3-2006-03-01.html#putobject for more put params - 'Metadata'?
		$client->stash($bucket, "{$foldername}/{$filename}", $contents, $stash_options);

		$form_values = $form->value()->toArray();
		foreach ($stash_attachments as $field) { // TODO: see below for FormFlash method when installed Form plugin reaches v3
			if (array_key_exists($field, $form_values)) {
				foreach($form_values[$field] as $upload) {
					// dump($upload);
					// $locator = $this->grav['locator'];
					// $path = $locator->findResource(__DIR__ . "/{$upload['path']}", TRUE); // NOPE

					// $file = File::instance(__DIR__ . "/{$upload['path']}"); // NOPE
					$filePath = Utils::fullPath($upload['path']);
					$upload_contents = file_get_contents($filePath);

					$client->stash($bucket, "{$foldername}/{$upload['name']}", $upload_contents, array(
						'ContentType' => $upload['type'],
						));
				}
			}
		}

		// FormFlash method for Form plugin >=v3
		// Get flash object in order to save the files.
		/*
		if (!empty($stash_attachments)) {
			// Get flash object in order to save the files.
			$flash = $form->getFlash();
			dump($flash); exit;
			$fields = $flash->getFilesByFields();
			dump($flash); exit;


			foreach ($fields as $key => $uploads) {
				foreach ($uploads as $upload) {
					if (null === $upload) {
						continue;
					}
					$destination = $upload->getDestination();
					$filesystem = Filesystem::getInstance();
					$folder = $filesystem->dirname($destination);
					if (!is_dir($folder) && !@mkdir($folder, 0777, true) && !is_dir($folder)) {
						$grav = Grav::instance();
						throw new \RuntimeException(sprintf($grav['language']->translate('PLUGIN_FORM.FILEUPLOAD_UNABLE_TO_MOVE', null, true), '"' . $upload->getClientFilename() . '"', $destination));
					}
					try {
						$upload->moveTo($destination);
					} catch (\RuntimeException $e) {
						$grav = Grav::instance();
						throw new \RuntimeException(sprintf($grav['language']->translate('PLUGIN_FORM.FILEUPLOAD_UNABLE_TO_MOVE', null, true), '"' . $upload->getClientFilename() . '"', $destination));
					}
				}
			}
			$flash->clearFiles();
		}
		// dump($form->value()->toArray()); exit;
		*/

	}

	private function extractMetadata($itemData) {
		$ret['author'] = $itemData['ip'];
		return $ret;
	}
}
