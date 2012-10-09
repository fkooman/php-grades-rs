# Introduction
This is a demonstration OAuth Resource Server (RS) integrating with the 
`php-oauth` Authorization Server (AS).

The API documentation is available at the installation URL, e.g.: 
`http://localhost/oauth/php-oauth-grades-rs/`.

# Configuration
To install the required dependencies run:

    $ sh docs/install_dependencies.sh

To set file permissions and setup the configuration file run:

    $ sh docs/configure.sh

Now you can modify the `tokenEndpoint` option in `config/rs.ini` to point to
your `php-oauth` installation's token endpoint. Also update the 
`resourceServerId` and `resourceServerSecret` fields to match your registration 
at the AS.

Don't forget to add the Apache configuration snippet that was shown as output
of the `configure.sh` script to your Apache configuration.
