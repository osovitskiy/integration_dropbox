#!/bin/bash
DIR="$( dirname -- "$0"; )"
docker build -t nextcloud_dropbox_duilder ${DIR} && docker run -it -v=${DIR}:/src nextcloud_dropbox_duilder
