#!/bin/sh
set -eu

umask 077

case " ${RENEWED_DOMAINS:-test.kolodahearthstone.com} " in
    *" test.kolodahearthstone.com "*) ;;
    *) exit 0 ;;
esac

lineage=${RENEWED_LINEAGE:-/etc/letsencrypt/live/test.kolodahearthstone.com}
certificate="$lineage/fullchain.pem"
private_key="$lineage/privkey.pem"
destination_dir=/etc/nginx/ssl/test.kolodahearthstone.com
ssh_config=/etc/ssh/koloda-moscow-via-novosibirsk.conf
lock_file=/run/lock/test-kolodahearthstone-cert-sync.lock

test -r "$certificate" -a -r "$private_key"
openssl x509 -in "$certificate" -noout -checkend 1209600 -checkhost test.kolodahearthstone.com
certificate_public_key=$(openssl x509 -in "$certificate" -pubkey -noout | sha256sum | awk '{print $1}')
private_public_key=$(openssl pkey -in "$private_key" -pubout | sha256sum | awk '{print $1}')
test "$certificate_public_key" = "$private_public_key"

exec 9>"$lock_file"
flock -n 9 || exit 0

sync_remote() {
    label=$1
    remote=$2
    suffix=$$
    incoming_certificate=/tmp/test-khs-fullchain.$suffix
    incoming_private_key=/tmp/test-khs-privkey.$suffix

    scp -q -F "$ssh_config" "$certificate" "$remote:$incoming_certificate"
    scp -q -F "$ssh_config" "$private_key" "$remote:$incoming_private_key"
    ssh -F "$ssh_config" "$remote" "set -eu
mkdir -p '$destination_dir'
openssl x509 -in '$incoming_certificate' -noout -checkend 1209600 -checkhost test.kolodahearthstone.com
certificate_public_key=\$(openssl x509 -in '$incoming_certificate' -pubkey -noout | sha256sum | awk '{print \$1}')
private_public_key=\$(openssl pkey -in '$incoming_private_key' -pubout | sha256sum | awk '{print \$1}')
test \"\$certificate_public_key\" = \"\$private_public_key\"
install -o root -g root -m 0644 '$incoming_certificate' '$destination_dir/fullchain.pem'
install -o root -g root -m 0600 '$incoming_private_key' '$destination_dir/privkey.pem'
rm -f '$incoming_certificate' '$incoming_private_key'
nginx -t
systemctl reload nginx"
    echo "certificate installed on $label"
}

sync_remote moscow koloda-ru-moscow
sync_remote novosibirsk koloda-ru-novosibirsk-jump
