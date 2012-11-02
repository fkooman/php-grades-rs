# Introduction
This is a demonstration OAuth Resource Server.

The API documentation is available at the installation URL, e.g.: 
`http://localhost/oauth/php-oauth-grades-rs/`.

# Configuration
To install the required dependencies run:

    $ sh docs/install_dependencies.sh

To set file permissions and setup the configuration file run:

    $ sh docs/configure.sh

Now you can modify the `tokenInfoEndpoint` option in `config/rs.ini` to point to
the OAuth authorization server's tokenInfo endpoint.

Don't forget to add the Apache configuration snippet that was shown as output
of the `configure.sh` script to your Apache configuration.
