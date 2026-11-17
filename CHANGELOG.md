# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-17

### Added

- Store pickup shipping carrier with one rate per pickup location, each with an optional price of its own.
- Pickup locations maintained in the admin, with per store view texts for name, opening hours and note.
- The location address is written to the order, so emails, invoices, the order grid and exports show it, and the
  customer's own address is restored when they switch back to delivery.
- Pickup location column and filter in the order grid.
- "Ready for Pickup" button on the order view and a mass action in the order grid: ships the order, sets the new
  order status "Ready for Pickup", emails the customer and adds an order comment in the store's language.
- Translatable ready-for-pickup email for customers and guests, with optional copy.
- Optional rules to offer payment methods only for pickup orders or to hide them for pickup orders.
- Protection against renaming or removing a location that open carts or orders still use.
- Extension points for location providers, availability checks, order placement processors and shipment arguments.
- A service contract to mark an order as ready for pickup from another module.
- English, German and Italian translations.

[Unreleased]: https://github.com/pixelperfectat/magento2-module-store-pickup/compare/0.1.0...HEAD
[0.1.0]: https://github.com/pixelperfectat/magento2-module-store-pickup/releases/tag/0.1.0
