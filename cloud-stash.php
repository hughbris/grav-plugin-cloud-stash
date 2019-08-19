<?php
namespace Grav\Plugin;

require_once __DIR__ . '/vendor/autoload.php';

use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;
use RocketTheme\Toolbox\File\File;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\S3\Exception\S3Exception;

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

		// saveFormPDF custom action
		switch ($action) {
			case 'stash_pdf':
				$this->saveToStash($event);
				break;
		}
		// TODO: save yaml
	}

	/**
	 * Save PDF formatted data into a cloud stash
	 *
	 * @param Event $event
	 */
	public function saveToStash(Event $event) {

		$form = $event['form'];
		$params = $event['params'];

		$prefix = array_key_exists('fileprefix', $params) ? $params['fileprefix'] : '';
		$format = array_key_exists('dateformat', $params) ? $params['dateformat'] : 'Ymd-His-u';
		$postfix = array_key_exists('filepostfix', $params) ? $params['filepostfix'] : '';
		$ext = '.pdf';

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

		$foldername = array_key_exists('foldername', $params) ? $params['foldername'] : pathinfo($filename,  PATHINFO_FILENAME) /* TODO: not tested for filenames specified with filename parameter */;
		$foldername = $twig->processString($foldername, $vars);

		$html = $twig->processString(array_key_exists('body', $params) ? $params['body'] : '{% include "forms/data.html.twig" %}', $vars);

		$metadata = $this->extractMetadata($form->getData());

		$snappy = new SnappyManager($this->grav);
		$pdf = $snappy->servePDF([$html], $metadata);

		// dump($foldername); exit;
		// from AWS docs
		// TODO: improve error handling, move this out to its own function/method - params: region, credentials, bucket, filename, filebody
		// TODO: async?
		$bucket = $params['bucket'] ? $params['bucket'] : 'BUCKET_NOT_SPECIFIED'; // the fallback
		$stash_yaml_path = 'plugins.cloud-stash.stashes.AWS';
		$s3Client = new S3Client([
			'version'     => 'latest',
			'region'      => $this->grav['config']->get("{$stash_yaml_path}.region"),
			'credentials' => [
				'key'    => $this->grav['config']->get("{$stash_yaml_path}.key"),
				'secret' => $this->grav['config']->get("{$stash_yaml_path}.secret"),
			],
		]);
		// $client = $sdk->createS3();

		try {
			$result = $s3Client->putObject([
				'Bucket' => $bucket,
				'Key' => "{$foldername}/{$filename}",
				'Body' => $pdf,
				'ContentType' => 'application/pdf',
			]);

			$form_values = $form->value()->toArray();
			foreach ($stash_attachments as $field) {
				if (array_key_exists($field, $form_values)) {
					foreach($form_values[$field] as $upload) {
						// dump($upload);
						// $locator = $this->grav['locator'];
						// $path = $locator->findResource(__DIR__ . "/{$upload['path']}", TRUE); // NOPE

						// $file = File::instance(__DIR__ . "/{$upload['path']}"); // NOPE
						$file = file_get_contents($_SERVER['DOCUMENT_ROOT'] . "/{$upload['path']}");
						// dump($upload); exit;

						$result = $s3Client->putObject([
							'Bucket' => $bucket,
							'Key' => "{$foldername}/{$upload['name']}",
							'Body' => $file,
							'ContentType' => $upload['type'],
						]);
					}
				}
			}

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
		catch (S3Exception $e) {
			// Catch an S3 specific exception.
			echo 'S3Exception<br>';
			echo $e->getMessage();
		}
		catch (AwsException $e) {
			// This catches the more generic AwsException. You can grab information
			// from the exception using methods of the exception object.
			echo 'AwsException<br>';
			echo $e->getAwsRequestId() . "\n";
			echo $e->getAwsErrorType() . "\n";
			echo $e->getAwsErrorCode() . "\n";

			// This dumps any modeled response data, if supported by the service
			// Specific members can be accessed directly (e.g. $e['MemberName'])
			var_dump($e->toArray());
		}

	}

	private function extractMetadata($itemData) {
		$ret['author'] = $itemData['ip'];
		return $ret;
	}
}
