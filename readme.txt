=== Zaygl IP Blocker and Anti-DDoS ===
Contributors: zoyakn
Tags: security, ip blocker, ddos protection, ip logger, firewall
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Log visitor IPs, view top traffic sources, and easily block suspicious IP addresses to protect your WordPress website.

== Description ==

Zaygl IP Blocker and Anti-DDoS is a lightweight WordPress plugin that helps site owners monitor visitor IP activity and block suspicious traffic.

The plugin records visitor requests and displays useful statistics inside the WordPress admin panel. This allows you to quickly identify unusual traffic patterns, repeated requests from the same IP address, or possible bot activity.

With one click, you can block problematic IP addresses and prevent them from accessing your website.

The plugin is designed to be simple, fast, and safe to run on production sites.

== Main Features ==

* Logs visitor IP addresses and requests
* Displays **Top IP addresses by activity**
* Shows traffic statistics for:
  * last 5 minutes
  * last 1 hour
  * last 24 hours
  * last 3 days
  * last 7 days
* View **recent requests** from visitors
* Block or unblock IP addresses with a single click
* Bulk block or unblock multiple IPs
* Automatically deny access to blocked IP addresses
* Optional **country detection for IP addresses**
* Built-in **IP notes system** to track suspicious visitors
* Export IP lists as CSV
* Lightweight and optimized for performance

== Dashboard Overview ==

The plugin adds a new Zaygl IP Blocker page in the WordPress admin panel.

View traffic statistics

See which IPs generate the most requests

Filter traffic by country

Search for specific IP addresses

Block or unblock IP addresses

Add notes for suspicious IPs

Export reports

== Who Is This Plugin For? ==

This plugin is useful for:

* Website owners who want to monitor traffic activity
* WordPress administrators dealing with spam or bot traffic
* Sites experiencing suspicious traffic spikes
* Developers who want a simple IP logging and blocking tool

It is especially helpful for identifying suspicious activity and blocking problematic visitors quickly.

== Blocking Behavior ==

When an IP address is blocked:

* The visitor immediately receives a **403 Forbidden** response
* The request is stopped before WordPress loads the page
* The blocked IP cannot access the site until it is unblocked

== Country Detection ==

The plugin can optionally detect the visitor's country.

Available methods include:

* Cloudflare country header
* Local MaxMind GeoLite2 database
* Remote IP geolocation API

Country detection is optional and can be disabled.

== External Services ==

The plugin can optionally use external services to determine the country of a visitor IP address.

These services are used **only if the administrator enables remote geolocation** in the plugin settings.

The following services may be used:

ipapi.co  
Service: https://ipapi.co/  
Privacy Policy: https://ipapi.co/privacy/  
Terms: https://ipapi.co/terms/

ip-api.com  
Service: https://ip-api.com/  
Privacy Policy and Terms of Conditions: https://ip-api.com/docs/legal  

When enabled, the visitor IP address is sent to the selected service to retrieve a country code.

No data is transmitted unless the administrator explicitly enables remote geolocation in the plugin settings.

== Data Storage ==

The plugin stores the following information locally in the WordPress database:

* Visitor IP address
* Request time
* Requested URL
* User agent
* Country code (if available)

This data is stored only on your own website database.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress Plugins screen
3. Open **IP Guardian** in the WordPress admin menu

No additional configuration is required.

== Frequently Asked Questions ==

= Does the plugin slow down my website? =

No. The plugin is designed to be lightweight and runs minimal logic during page requests.

= Can I disable IP logging? =

Yes. Logging behavior can be controlled in the plugin settings.

= Can I block multiple IPs at once? =

Yes. The plugin supports bulk blocking and unblocking actions.

= Does this plugin replace a firewall? =

No. This plugin is a monitoring and blocking tool. It is not a full firewall solution.

== Screenshots ==

1. Traffic overview showing top IP addresses
2. Recent requests table
3. Blocking and unblocking IP addresses
4. IP notes and comments system
5. Plugin settings panel
6. Plugin info panel


== Changelog ==

= 1.0.0 =
* Initial release
* IP logging system
* Traffic statistics tables
* Manual IP blocking
* Bulk block and unblock actions
* IP notes system
* CSV export
* Optional geolocation support

== Upgrade Notice ==

= 1.0.0 =
Initial release of Zaygl IP Blocker and Anti-DDoS.