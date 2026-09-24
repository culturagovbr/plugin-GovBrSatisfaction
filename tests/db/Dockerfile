# Banco de testes com o geocodificador tiger.
#
# O dump do repositório principal faz `CREATE EXTENSION postgis_tiger_geocoder`,
# mas a imagem `postgis/postgis:16-master` que ele usa não traz essa extensão:
# o arquivo de controle não existe, o psql aborta, o container do banco morre no
# boot e a suíte fica pendurada esperando uma conexão que nunca vem.
#
# A extensão vem num pacote à parte do PostgreSQL, e instalá-la é tudo o que
# falta. Fazer isso aqui mantém o repositório principal intocado — a alternativa
# seria editar o dump do core, que não é deste plugin.
FROM postgis/postgis:16-master

RUN apt-get update \
    && apt-get install -y --no-install-recommends postgresql-16-postgis-3-scripts \
    && rm -rf /var/lib/apt/lists/*
