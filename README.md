# ZohoCalendar
PHP library for Zoho Calendar/Events

This is a PHP library for using Zoho's calendar.  Their API docs weren't super-clear, so I created this to help others use the API.
This doesn't have all the features of the API as I just ripped this file out of an app for my company.  This is just the features we use.
Hopefully using this, you can figure out how to access the other features.  Maybe I can update this with more stuff in the future

## How to use
1. Put this PHP file somewhere on your server
2. `include` it in other PHP files that you can access from the webbrowser (like `https://example.com/zoho/login.php` or `https://example.com/zoho/oauth.php`)
3. Create an API key at https://api-console.zoho.com
4. Point the "redirect url" to your file (`https://example.com/zoho/oauth.php` or whatever)

## Zoho `login.php` and `oauth.php`
Here is the `login.php` file that you can use to use the library.  This is just an example, create your project however you see fit.

```
<?php
require_once('ZohoCalendar.php');

$zoho = new ZohoCalendar;
$loginUrl = $zoho->getAuthURL();

header('Location: ' . $loginUrl);
```

Here's the `oauth.php` file.

```
<?php
require_once('ZohoCalendar.php');

$zoho = new ZohoCalendar;

$code = isset($_GET['code']) ? $_GET['code'] : NULL;
$error = isset($_GET['error']) ? $_GET['error'] : NULL;

if ($code && $error !== 'access_denied') {
	$tokens = $zoho->login($code);
	
	// Now you can save the following in your database:
	// $tokens['refresh_token']
	// $tokens['access_token']
	// $tokens['expires_in']
	
	// The "access_token" is what used to make requests.
	// It can expire.  The "expires_in" is how many seconds from now it expires.
	
	// The "refresh_token" is used when the "access_token" expires.
	// You use it to get another one (with a new expiration).
	
	// Before every request, check if the token is expired.
	// If it is expired, get a new token like this
	$tokens = $zoho->refreshToken($tokens['refresh_token']);
	
	// Create an event
	$eventDetails = $zoho->addEvent(
		$tokens['access_token'], 'calendar-id', 'an event',
		# Use $date->format('c') where $date is a DateTime object to get these dates
		['start' => '20210501T063000-0400', 'end' => '20210501T101500-0400'],
		'123 fake street', 'organizer'
	);
}
else {
	die('Zoho login Cancelled.');
}
```
