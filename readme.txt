=== Simba Plugin Updates Manager ===
Contributors: DavidAnderson
Requires at least: 3.1
Tested up to: 4.1
Stable tag: 1.4.0
Tags: plugin updates, updates server, wordpress updates, wordpress plugin updates
License: MIT
Donate link: http://david.dw-perspective.org.uk/donate

Provides a facility for hosting and distributing updates for your own WordPress plugins

== Description ==

This plugin enables you to host updates for your own plugins from your own WordPress site.

i.e. It provides a service for the availability and download of WordPress plugin updates - just like the wordpress.org plugin repository.

It is a free, cleaned-up version of the plugin updates server that has been providing plugin updates to thousands of users of the paid versions of <a href="https://updraftplus.com">the UpdraftPlus backup/restore/clone WordPress plugin</a> since 2013.

The best way to get a feel for its features is to take a look at the available screenshots.

= Features =

* Manage multiple plugins

* Have multiple different zips (i.e. different plugin versions) available for your plugins

* Have sophisticated rules for which zip a particular user gets delivered (e.g. send them an older version if they are on an old version of WordPress or PHP)

* Counts plugin downloads, by version - calculate how many active users you have

* Shortcode provided for showing users on your website what plugins are available

This version of the plugin supports hosting free plugins only - i.e. it does not support any restrictions upon access to plugins and updates. A premium version that adds support for paid plugins, and includes WooCommerce integration, may be released in future.

Running an updates server is one part of providing plugin updates to your users. You will also need to add code in your plugin to point towards that updates server. A popular class used for this purpose, that requires you to do nothing more than include it and tell it the updates URL, is available here: https://github.com/YahnisElsts/plugin-update-checker

= Other information =

- Some other plugins you may be interested in: https://www.simbahosting.co.uk/s3/shop/ and https://updraftplus.com/

- This plugin is ready for translations, and we would welcome new translations (please post them in the support forum.

== Installation ==

Standard WordPress installation; either:

- Go to the Plugins -> Add New screen in your dashboard and search for this plugin; then install and activate it.

Or

- Upload this plugin's zip file into Plugins -> Add New -> Upload in your dashboard; then activate it.

After installation, you will want to configure this plugin. To find its settings, look for the "Plugins Manager" entry in your WordPress dashboard menu.

To show users on your website what plugins they can download, use this shortcode (changing the value of userid to match the ID of the WordPress user who is providing the plugins): [udmanager showunpurchased="free" userid="1"]


== Frequently Asked Questions ==

None yet.

== Changelog ==

= 1.4.0 - 2015-03-1 =

* RELEASE: First public release. Supports hosting + providing updates for free plugins, with multiple versions and download rules.

== Screenshots ==

1. Display of managed plugins

2. Adding a new plugin

3. Adding a new zip for a plugin

4. Managing zips for a plugin

5. Adding a download rule for a plugin

6. Managing download rules for a plugin

7. Showing users on your website the plugins that they can download, using a shortcode

== License ==

The MIT License (MIT)

Copyright © 2015- David Anderson, https://www.simbahosting.co.uk

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

== Upgrade Notice ==
* 1.4.0 : Initial release