# O dump do core faz `CREATE EXTENSION postgis_tiger_geocoder`; a imagem que
# ele declara não traz a extensão e o banco morre no boot. Instalar o pacote
# aqui evita editar o dump.
FROM postgis/postgis:16-master

RUN apt-get update \
    && apt-get install -y --no-install-recommends postgresql-16-postgis-3-scripts \
    && rm -rf /var/lib/apt/lists/*
