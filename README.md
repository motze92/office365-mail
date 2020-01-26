# Laravel Microsoft Graph Mail driver


Mail driver for Laravel to send emails using Microsoft Graph without user authetication.

To use this package you have to register your application [here](https://go.microsoft.com/fwlink/?linkid=2083908). More informations [here](https://docs.microsoft.com/en-us/graph/auth-register-app-v2).

## Install the Package
You can install the package with Composer, either run `composer require motze92/office365-mail`, or edit your `composer.json` file:
```
{
    "require": {
        "motze92/office365-mail": "^1.0"
    }
}
```

To publish the config file use this command:

```php

php artisan vendor:publish --tag=office365mail

```

## Configure

To obtain needed config values use this [instructions](https://docs.microsoft.com/en-us/graph/auth-v2-service).

.env

```
MAIL_DRIVER=office365mail

OFFICE365MAIL_CLIENT_ID=YOUR-MS-GRAPH-CLIENT-ID
OFFICE365MAIL_TENANT=YOUR-MS-GRAPH-TENANT-ID
OFFICE365MAIL_CLIENT_SECRET=YOUR-MS-GRAPH-CLIENT-SECRET

```

## Copyright and license

Copyright (c) Moritz Mair. All Rights Reserved. Licensed under the MIT [license](LICENSE).