# Oracle Instant Client + oci8, downloaded at build time (no binaries in git).

ENV ORACLE_HOME=/opt/oracle/instantclient
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y -o Dpkg::Options::="--force-confold" --no-install-recommends --no-install-suggests \
      php${DDEV_PHP_VERSION}-dev php-pear build-essential unzip libaio1t64 \
    && ln -sf /usr/lib/x86_64-linux-gnu/libaio.so.1t64 /usr/lib/x86_64-linux-gnu/libaio.so.1 \
    && mkdir -p /opt/oracle && cd /opt/oracle \
    && curl -fsSLo basic.zip https://download.oracle.com/otn_software/linux/instantclient/instantclient-basiclite-linuxx64.zip \
    && curl -fsSLo sdk.zip https://download.oracle.com/otn_software/linux/instantclient/instantclient-sdk-linuxx64.zip \
    && unzip -oq basic.zip && unzip -oq sdk.zip && rm basic.zip sdk.zip \
    && mv instantclient_* instantclient \
    && echo "$ORACLE_HOME" > /etc/ld.so.conf.d/oracle.conf && ldconfig \
    && echo "instantclient,$ORACLE_HOME" | pecl install oci8 \
    && echo "extension=oci8.so" > /etc/php/${DDEV_PHP_VERSION}/mods-available/oci8.ini \
    && phpenmod oci8
