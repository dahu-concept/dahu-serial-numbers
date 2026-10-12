=== Dahu Serial Numbers ===
Contributors: hugovialjaime
Tags: woocommerce, serial numbers, traceability, warranty, inventory
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Per-unit traceability for WooCommerce: a serial number on every unit you ship, a lifecycle with real rules, a full history, and a packing screen for the bench.

== Description ==

Two questions, asked months apart.

A customer calls about a part that has failed. Which one did they get, which
batch was it from, and how many others from that batch went out? Without serial
numbers the answer is the order date and a guess.

A batch turns out to be faulty. Which customers have one, and which units are
still on the shelf? Without serial numbers the answer is everybody who bought
that product, which is both too many people to write to and not the right ones.

Dahu Serial Numbers answers both, because every unit has a row and every row
has a history.

= The lifecycle =

Eleven statuses — received, in stock, assigned to an order, shipped, return
expected, returned as described, returned not as described, in repair,
replaced, going back to stock, destroyed — and a matrix that says which moves
are legal. Every status change goes through one check, and there is no flag
anywhere that turns it off.

A unit put back in stock is a free unit: the order, the order line, the service
ticket and the replacement pointer are all cleared. Nothing is lost — what was
cleared is written into the history line.

= The packing screen =

A page of its own, off until you switch it on, built for a tablet on a
workbench. It signs in with one login per bench rather than one per person: a
packer does not need a WordPress account to scan a number, and giving them one
means giving them the admin.

It lists the orders waiting, takes one scan at a time and checks it against the
shop as it happens, refuses a line more units than it ordered, and will not
close a parcel while a unit on it is unaccounted for.

A barcode reader is a keyboard, and many suppliers encode a URL on the label
rather than the number. The whole address lands in the field, and the plugin
reduces it to the serial.

= Shipping =

The plugin closes a parcel: every unit is recorded as having left, the carrier
and tracking number go on the order, and the order moves on. It does not talk
to a carrier — no label creation, no API credentials — because that needs an
account and a configuration particular to one shop. A carrier integration plugs
in with two filters and no changes to this plugin.

= Reading a date out of a serial number =

Nothing is read by default, which is the correct answer for a shop whose serial
numbers encode nothing. A scheme for the common shape — first letter the year,
second letter the month, over a twenty-six year cycle — ships with the plugin
and is enabled with one line of code.

= Times =

Every datetime this plugin stores is UTC, converted to the shop's timezone when
it is shown.

= External services =

This plugin does not contact any external service. It makes no outbound request
of any kind, and ships no third-party library.

== Installation ==

1. Install and activate the plugin. Four database tables are created.
2. Tick "Serial numbers" under Inventory on the products that carry one.
3. Create a workshop post under Serial numbers → Workshop posts.
4. Switch the packing screen on under Serial numbers → Settings, and open
   /packing/ on the bench tablet.

== Frequently Asked Questions ==

= Does it need WooCommerce? =

Yes, 7.0 or later. It declares compatibility with High-Performance Order
Storage and works with either order store.

= Does it send anything anywhere? =

No. The plugin makes no outbound request of any kind.

= What happens to my data when I delete the plugin? =

Nothing, unless you ticked the box on the settings screen. The serial numbers
and their history are the only record of which unit went to which customer, and
uninstalling is most often a step in reinstalling.

= Can I import serial numbers I already have? =

Through code, yes: `Dahu_Serial_Numbers_Manager::create()` takes a serial, a
supplier batch, a product and an optional date, and accepts an explicit history
date so an import records when things really happened rather than the day of
the import. There is no import screen in this version.

= Why is the packing screen not in the admin? =

Because the person using it is not an administrator and should not have to be.
It signs in with a credential for the bench, not for a person, and the history
records which bench did what.

== Screenshots ==

1. The serial numbers list, with a tally by status.
2. One unit, and everywhere it has been.
3. Workshop posts.
4. The packing screen on a bench tablet.

== Changelog ==

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.0 =
First release.
