FROM eclipse-temurin:8-jdk

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
    git \
    maven \
    mariadb-client \
    && apt-get clean && \
    rm -rf /var/lib/apt/lists/*