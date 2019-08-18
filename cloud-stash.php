<?php
namespace Grav\Plugin;

require_once __DIR__ . '/vendor/autoload.php';

use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\S3\Exception\S3Exception;

/**
 * Class CloudStashPlugin
 * @package Grav\Plugin
 */
class CloudStashPlugin extends Plugin
{
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
	public static function getSubscribedEvents()
	{
		return [
			'onPluginsInitialized' => ['onPluginsInitialized', 0]
		];
	}

	/**
	 * Initialize the plugin
	 */
	public function onPluginsInitialized()
	{
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
	 * [onFormProcessed] Process a registration form. Handles the following actions:
	 *
	 * - register_user: registers a user
	 * - update_user: updates user profile
	 *
	 * @param Event $event
	 * @throws \RuntimeException
	 */
	public function customFormActions(Event $event)
	{
		$form = $event['form'];
		$action = $event['action'];

		// saveFormPDF custom action
		switch ($action) {
			case 'stash_pdf':
				$this->saveToStash($event);
				break;
		}
	}

	/**
	 * Do some work for this event, full details of events can be found
	 * on the learn site: http://learn.getgrav.org/plugins/event-hooks
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

		$twig = $this->grav['twig'];
		$vars = [
			'form' => $form,
		];
		$twig->itemData = $form->getData(); // FIXME for default data.html template below - might work OK
		$filename = $twig->processString($filename, $vars);

		$html = $twig->processString(array_key_exists('body', $params) ? $params['body'] : '{% include "forms/data.html.twig" %}', $vars);

		$metadata = $this->extractMetadata($form->getData());

		$snappy = new SnappyManager($this->grav);
		$pdf = $snappy->servePDF([$html], $metadata);

		// dump($params['provider']); exit;
		// from AWS docs
		// TODO: improve error handling, move this out to its own function/method - params: region, credentials, bucket, filename, filebody
		// TODO: async?
		$bucket = $params['bucket'] ? $params['bucket'] : 'BUCKET_NOT_SPECIFIED';
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
				'Key' => $filename, // TODO: folder
				'Body' => $pdf,
			]);
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
