# PipraPay Payment Gateway for FOSSBilling

PipraPay payment gateway module for FOSSBilling.

This module allows FOSSBilling users to accept payments through PipraPay.

## Features

- One-time invoice payments
- PipraPay Checkout integration
- Automatic payment verification
- Webhook/IPN support
- Transaction ID handling
- Invoice payment automation
- Configurable PipraPay API URL
- Configurable API key
- Configurable currency

## Requirements

- FOSSBilling
- PHP with cURL enabled
- Active PipraPay account
- PipraPay API key

## Installation

Copy the module files into the FOSSBilling payment adapter directory.

The module structure should be:

```text
piprapay/
├── piprapay.php
└── favicon.png
