# Cashier-Braintree

[![Build Status](https://travis-ci.org/LimeDeck/cashier-braintree.svg)](https://travis-ci.org/LimeDeck/cashier-braintree)
[![Total Downloads](https://poser.pugx.org/limedeck/cashier-braintree/downloads)](https://packagist.org/packages/limedeck/cashier-braintree)
[![Latest Unstable Version](https://poser.pugx.org/limedeck/cashier-braintree/v/unstable)](https://packagist.org/packages/limedeck/cashier-braintree)
[![License](https://poser.pugx.org/limedeck/cashier-braintree/license)](https://packagist.org/packages/limedeck/cashier-braintree)

## Introduction

Cashier-Braintree is a rewrite of [Laravel Cashier](https://github.com/laravel/cashier) using Braintree instead of Stripe as a payment gateway. It tries to remain somewhat consistent with Laravel Cashier, however some functionality is not present at the moment.

### *This package is currently in active development, use with caution.*

## Usage

### Installation
Add this repository to your `composer.json` file by running

`composer require limedeck/cashier-braintree`

### Setting up the environment
See the included `.env.example` file. You will need a Braintree sandbox account in order to be able to obtain the login information.

### Testing
First, setup the following within the Braintree Sandbox console in order to pass the tests.

* Create a plan with an id and name `monthly-10-1` and price of $10.00 (or your regional equivalent)
* Create a plan with an id and name `monthly-10-2` and price of $10.00 (or your regional equivalent)
* Create a plan with an id and name `monthly-20` and price of $20.00 (or your regional equivalent)
* Create a Discount with an id and name `coupon-1` and amount of $5.00 (or your regional equivalent)

In order to run the tests, run `phpunit` or `vendor/bin/phpunit`.

## Contributing
If you want to contribute to this package, please fork this repository and create a pull request. Thank you for your help!

**List of contributors:**

* [Rudolf Halas](https://github.com/HRcc) - mail@hrcc.sk
* [Jakub Homoly](https://github.com/insanesvk) - jakubhomoly@gmail.com

## License

LimeDeck Cashier-Braintree is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT).
