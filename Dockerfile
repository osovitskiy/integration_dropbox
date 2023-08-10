FROM alpine:3.18

RUN apk add --no-cache \
        curl \
        make \
        nodejs \
        npm \
        php \
        php81 \
        php81-ctype \
        php81-curl \
        php81-dom \
        php81-iconv \
        php81-mbstring \
        php81-openssl \
        php81-phar \
        php81-simplexml \
        php81-tokenizer \
        php81-xmlreader \
        php81-xmlwriter \
        tar \
        which

WORKDIR /src

CMD ["make", "build"]

