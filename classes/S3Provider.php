<?php
namespace Grav\Plugin\CloudStash;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\S3\Exception\S3Exception;

class S3Provider extends Provider {

	public function __construct() {
		parent::__construct();
		$this->settings = $this->grav['config']->get('plugins.cloud-stash.stashes.AWS');

		$this->client = new S3Client([
			'version'     => 'latest',
			'region'      => $this->settings['region'],
			'credentials' => [
				'key'    => $this->settings['key'],
				'secret' => $this->settings['secret'],
			],
		]);
		// $client = $sdk->createS3();
	}

	public function stash($bucket, $object_name, $object_contents, $object_params=[]) {
		// TODO: improve error handling
		// TODO: async?

		$putParams = array_merge([
			'Bucket' => $bucket,
			'Key' => $object_name,
			'Body' => $object_contents,
			], $object_params);

		try {
			$result = $this->client->putObject($putParams);
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
		return $result;
	}
}