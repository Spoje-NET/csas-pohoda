FROM debian:stable-slim

RUN apt-get update && apt-get install -y --no-install-recommends php-cli ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# csas-statement-tools, pohoda-abo-importer and csas-pohoda come from the VitexSoftware repository
COPY debian/*.deb /tmp/debs/
RUN apt-get update && apt-get install -y /tmp/debs/*.deb && rm -rf /var/lib/apt/lists/* /tmp/debs

ENV OUTPUT_DIR=/statements
VOLUME /statements

ENTRYPOINT ["csas-pohoda"]
CMD ["--help"]
