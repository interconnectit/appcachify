appcachify
==========

Adds an appcache manifest to WordPress via an iframed page. Automatically lists all queued scripts, styles and theme images.

## Installation

Upload the plugin to your plugins directory and activate it. There's no configuration involved or settings screen.

## What does it do?

The plugin adds an iframe to the footer of your website which points to `example.com/manifest`.

That URL is an empty page that references the generated manifest file at `example.com/manifest.appcache`.

The manifest itself is built in the following way:

 1. adds URLs of all queued scripts and styles
 2. searches theme files and folder for any images or other static assets
 3. if a theme has a 307.php template it is used as an offline fallback
 4. a timestamp of the most recently modified file is added to force appcache to refresh

The net result of all this is that your main static files are stored locally on your visitors devices. For mobile this greatly helps to improve download and rendering times.

## Adding items to the manifest

Appcache can do more than store static assets. You could cache entire pages, or add fallbacks for when a user is offline.

There are 3 main sections to a manifest:

### CACHE

The main `CACHE` section is for URLs that should be explicitly cached.

```php
<?php
add_filter( 'appcache_cache', function( $urls ) {
   $urls[] = '/page-available-offline/';
   return $urls;
} );
?>
```

### NETWORK

This section is for specifying URLs that should *never* be cached.
 
```php
<?php
add_filter( 'appcache_network', function( $urls ) {
   $urls[] = '*';
   $urls[] = '/online-only-page/';
   return $urls;
} );
?>
```

### FALLBACK

The fallback section allows you to set fallback pages or images if the user is offline.

```php
<?php
add_filter( 'appcache_fallback', function( $patterns ) {
   $patterns[] = 'wp-content/uploads/ wp-content/uploads/offline.jpg';
   return $patterns;
} );
?>
```

### The update header

Appcaches are refetched when the manifest file content changes so we add a few items as comments at the top of the file. 

 1. The current theme (and version if available)
 2. The most recent modified time of any files we find the server path for
 3. The size of all the files that we find a server path for

```php
<?php
add_filter( 'appcache_update_header', function( $headers ) {
   global $wpdb;
   $headers[ 'posts' ] = 'Posts modified: ' .  $wpdb->get_var( "SELECT post_modified FROM $wpdb->posts WHERE post_type = 'post' ORDER BY post_modified DESC LIMIT 1" );
   return $headers;
} );
?>
```

## More about appcache

I strongly recommend learning more about what you can do with appcache by reading the following articles:

 * http://www.html5rocks.com/en/tutorials/appcache/beginner/
 * http://alistapart.com/article/application-cache-is-a-douchebag

## License

GPLv3
