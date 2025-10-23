# BIIGLE Point To Polygon Module

[![Test status](https://github.com/biigle/ptp/workflows/Tests/badge.svg)](https://github.com/biigle/ptp/actions?query=workflow%3ATests)

A BIIGLE module for point to polygon conversion.

## Installation

1. Run `composer require biigle/ptp`.
2. Install the Python dependencies with `pip install -r requirements.txt`.
2. Add `Biigle\Modules\Ptp\PtpServiceProvider::class` to the `providers` array in `config/app.php`.
3. Run `php artisan vendor:publish --tag=public` to refresh the public assets of the modules. Do this for every update of this module.

## Configuration

Processing jobs require a GPU and are submitted to the `default` queue of the `gpu` connection. You can configure these with the `PTP_JOB_QUEUE` and `PTP_JOB_CONNECTION` environment variables.

## Developing

Take a look at the [development guide](https://github.com/biigle/core/blob/master/DEVELOPING.md) of the core repository to get started with the development setup.

Want to develop a new module? Head over to the [biigle/ptp](https://github.com/biigle/ptp) template repository.

## Contributions and bug reports

Contributions to BIIGLE are always welcome. Check out the [contribution guide](https://github.com/biigle/core/blob/master/CONTRIBUTING.md) to get started.
