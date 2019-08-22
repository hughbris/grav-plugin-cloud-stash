# Cloud Stash Plugin

The **Cloud Stash** Plugin is an extension for [Grav CMS](http://github.com/getgrav/grav). Stash your users' form data in a secure cloud repository.

You might want this plugin if your users submit sensitive information you don't want to store on your web server. If you use specific cloud storage services, you can send your users' data there using credentials that are limited to dropping items and don't allow reads. In so doing, if your web server is compromised, attackers will not be able to access your users' sensitive data.

This could be handy for:

* confidential surveys;
* application and registration forms with sensitive info (e.g. medical history, income details);
* secret ballots.

## Installation

Installing the Cloud Stash plugin can be done in one of three ways: The GPM (Grav Package Manager) installation method lets you quickly install the plugin with a simple terminal command, the manual method lets you do so via a zip file, and the admin method lets you do so via the Admin Plugin.

### Dependencies

This plugin requires the [Form plugin](https://github.com/getgrav/grav-plugin-form) to provide anything useful.

The [Snappygrav plugin](https://github.com/iusvar/grav-plugin-snappygrav) (>= v1.9.1) is also listed as a dependency, though you won't strictly need this if you don't need to stash PDF documents.

_If you use this plugin without producing PDFs_ (Snappygrav), you could install it manually according to the instructions below.

> Note that Snappygrav requires you to either [install or make sure you have a PDF creation library available](https://github.com/iusvar/grav-plugin-snappygrav#requirements) on your server.

### GPM Installation (Preferred)

To install the plugin via the [GPM](http://learn.getgrav.org/advanced/grav-gpm), through your system's terminal (also called the command line), navigate to the root of your Grav-installation, and enter:

    bin/gpm install cloud-stash

This will install the Cloud Stash plugin into your `/user/plugins`-directory within Grav. Its files can be found under `/your/site/grav/user/plugins/cloud-stash`.

### Manual Installation

To install the plugin manually, download the zip-version of this repository and unzip it under `/your/site/grav/user/plugins`. Then rename the folder to `cloud-stash`. You can find these files on [GitHub](https://github.com/hughbris/grav-plugin-cloud-stash) or via [GetGrav.org](http://getgrav.org/downloads/plugins#extras).

You should now have all the plugin files under

    /your/site/grav/user/plugins/cloud-stash

> NOTE: This plugin is a modular component for Grav which may require other plugins to operate, please see its [blueprints.yaml-file on GitHub](https://github.com/hughbris/grav-plugin-cloud-stash/blob/master/blueprints.yaml).

### Admin Plugin

If you use the Admin Plugin, you can install the plugin directly by browsing the `Plugins`-menu and clicking on the `Add` button.

## Configuration

Before configuring this plugin, you should copy the `user/plugins/cloud-stash/cloud-stash.yaml` to `user/config/plugins/cloud-stash.yaml` and only edit that copy.

Here is the default configuration and an explanation of available options:

```yaml
enabled: true
stashes:
  AWS:
    region: 'AWS_BUCKET_REGION'
    key: 'YOUR_KEY'
    secret: 'YOUR_SECRET'
```

* **enabled** toggles the plugin on and off
* **stashes** holds information about the cloud storage provider services you have set up and want to make available. It has been populated with dummy data. _Presently only the AWS S3 provider is supported._

Note that if you use the Admin Plugin, a file with your configuration named cloud-stash.yaml will be saved in the `user/config/plugins/`-folder once the configuration is saved in the Admin.

## Usage

At present the plugin only supports [Amazon Web Services S3](https://docs.aws.amazon.com/s3/index.html) [buckets](https://docs.aws.amazon.com/AmazonS3/latest/dev/Introduction.html#BasicsBucket), but has been developed to facilitate adding support for extra providers.

The plugin defines **two new [form actions](https://learn.getgrav.org/16/forms/forms/reference-form-actions)** for [Grav forms](https://github.com/getgrav/grav-plugin-form). Place these as required under the `process` form YAML property.

* **`stash`** saves a form data file, and optionally [file field attachments](https://learn.getgrav.org/16/forms/forms/fields-available#file-field) uploaded through the form, to a remote storage location that you specify.
* **`stash_pdf`** saves a formatted PDF file based on form input, and optionally [file fields](https://learn.getgrav.org/16/forms/forms/fields-available#file-field) uploaded through the form, to a remote storage location that you specify.

> If you use both of these actions, you probably only want to specify that file fields be stashed in one of those actions. If you specify any field twice, its attachment will be overwritten. This is mostly harmless except for the extra traffic and time taken.

### `stash` action

The parameters `fileprefix`, `filepostfix`, `dateformat`, `dateraw`, `filename`, `extension`, and `body` are available and function identically to the form plugin's bundled ['save' action](https://learn.getgrav.org/16/forms/forms/reference-form-actions#save) parameters.

Just like the 'save' action, if you omit the `body` parameter, your output will be formatted using the 'forms/data.html.twig' template from your theme, Form plugin, or other location in your Twig path.

`provider` specifies the provider of the cloud storage service and currently must be 'AWS' (S3).

`bucket` is S3-specific (AWS) and may be deprecated for a more provider-agnostic term in the near future. It specifies the name of the S3 bucket into which you want to stash your form data.

`add_uploads` is a YAML list of [file field](https://learn.getgrav.org/16/forms/forms/fields-available#file-field) names from the form, which indicates that you would like those files to be uploaded to the remote stash as well.

`operation` is _not_ supported and is ignored. Documents/objects are always _created_.

### Example

```yaml
    …
    process:
        …
        - stash:
            filename: "{{ 'questionnaire-' ~ form.value['timestamp']|date('Ymd-His') ~ '-' ~ form.value['respondent-name']|e|split(' ')|last|lower ~ '.yaml' }}"
            foldername: "{{ form.value['timestamp']|date('Ymd-His') ~ '-' ~ form.value['respondent-name']|e|split(' ')|last|lower }}"
            extension: yaml
            body: "{% include 'forms/data.txt.twig' %}"
            provider: AWS
            bucket: MY.BUCKET.NAME
            add_uploads:
                - attachments
                - supporting_documents
        …
```

### `stash_pdf` action

As per the `stash` action except that `extension` will be ignored and set to ".pdf".

### Example

```yaml
    …
    process:
        …
        - stash_pdf:
            filename: "{{ 'application-' ~ form.value['timestamp']|date('Ymd-His') ~ '-' ~ form.value['applicant-name']|e|split(' ')|last|lower ~ '.pdf' }}"
            foldername: "{{ form.value['timestamp']|date('Ymd-His') ~ '-' ~ form.value['applicant-name']|e|split(' ')|last|lower }}"
            body: "{% include 'forms/application-print.html.twig' %}"
            provider: AWS
            bucket: MY.BUCKET.NAME
        …
```

## Credits

This plugin makes use of a bunch of wonderful open source software and requires the [Snappygrav plugin](https://github.com/iusvar/grav-plugin-snappygrav) to produce PDFs for uploading/stashing.

Many thanks to [Matt Marsh (@marshmn)](https://github.com/marshmn) and [@robertorubioguardia](https://github.com/robertorubioguardia) for S3 advice and mentoring.

_TODO: more credits_

## To Do

The most important TODOs have been added as [repository issues](https://github.com/hughbris/grav-plugin-cloud-stash/issues) for now. _FIXME_

> [Dropbox](https://dropbox.com) support is _not_ a priority because it doesn't support write-only permissions, despite its name. It may, however, have value for its ability to provide seamless mount points to the user's local file system.
