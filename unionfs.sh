#!/bin/sh

# unionfs.sh
# Part of Bastille Extension for XigmaNAS x64 12.x and later.
# Bastille Extension Forum:  https://www.xigmanas.com/forums/viewtopic.php?f=71&t=14848
# Bastille Extension GitHub: https://github.com/JRGTH/xigmanas-bastille-extension
# Bastille Homepage:         http://bastillebsd.org/
# Bastille GitHub:           https://github.com/BastilleBSD/bastille
#
# Debug script
#set -x

# Copyright (c) 2019-2021, José Rivera (joserprg@gmail.com).
# All rights reserved.

# Redistribution and use in source and binary forms, with or without
# modification, are permitted provided that the following conditions
# are met:
# 1. Redistributions of source code must retain the above copyright
#    notice, this list of conditions and the following disclaimer.
# 2. Redistributions in binary form must reproduce the above copyright
#    notice, this list of conditions and the following disclaimer in the
#    documentation and/or other materials provided with the distribution.
# 3. Neither the name of the developer nor the names of contributors
#    may be used to endorse or promote products derived from this software
#    without specific prior written permission.

# THIS SOFTWARE IS PROVIDED BY THE DEVELOPER ``AS IS'' AND
# ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
# IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
# ARE DISCLAIMED.  IN NO EVENT SHALL THE DEVELOPER OR CONTRIBUTORS BE LIABLE
# FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
# DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS
# OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION)
# HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
# LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY
# OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF
# SUCH DAMAGE.

# Set environment.
PATH=${PATH}:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin

# Global variables.
CWDIR=$(dirname $(realpath $0))
PRDPLATFORM=$(cat /etc/platform)
SCRIPTNAME=$(basename $0)
APPNAME="bastille"
EXTCONF="/conf/${APPNAME}_config"

error_notify() {
	# Log/notify message on error and exit.
	MSG="${*}"
	logger -t "${SCRIPTNAME}" "${MSG}"
	echo -e "${MSG}" >&2; exit 1
}

platform_check()
{
	# Check for working platform.
	if [ "${PRDPLATFORM}" = "x64-embedded" ]; then
		pkg_symlink
	else
		echo "Cleaning the pkg cache."
		pkg clean -y -a
	fi
}

load_kmods() {
	required_mods="fdescfs linprocfs linsysfs tmpfs"
	linuxarc_mods="linux linux64"

	if [ ! -f "/boot/loader.conf" ]; then
		touch /boot/loader.conf
	else
		chmod 0644 /boot/loader.conf
	fi

	# Skip already loaded known modules.
	for _req_kmod in ${required_mods}; do
		if ! sysrc -f /boot/loader.conf -qn ${_req_kmod}_load=YES | grep -q "YES"; then
			sysrc -f /boot/loader.conf ${_req_kmod}_load=YES
		fi
		if ! kldstat -m ${_req_kmod} >/dev/null 2>&1; then
			echo "Loading kernel module: ${_req_kmod}"
			kldload -v ${_req_kmod}
		fi
	done

	# Mandatory Linux modules/rc.
	for _lin_kmod in ${linuxarc_mods}; do
		if ! kldstat -n ${_lin_kmod} >/dev/null 2>&1; then
			echo "Loading kernel module: ${_lin_kmod}"
			kldload -v ${_lin_kmod}
		fi
	done
	if ! sysrc -qn linux_enable=YES | grep -q "YES"; then
		sysrc linux_enable=YES
	fi
}

pkg_symlink() {
	if ! sysrc -f ${CWDIR}${EXTCONF} -qn LINUX_COMPAT_SUPPORT | grep -q "YES"; then
		echo "Creating pkg environment for embedded platforms."

		if [ -d "/var/cache/pkg" ]; then
			if [ ! -L "/var/cache/pkg" ]; then
				rm -R /var/cache/pkg
				mkdir -p ${CWDIR}/system/cache/pkg
				ln -vFs ${CWDIR}/system/cache/pkg /var/cache/pkg
			fi
		else
			mkdir -m 0755 -p /var/cache
			mkdir -p ${CWDIR}/system/cache/pkg
			ln -vFs ${CWDIR}/system/cache/pkg /var/cache/pkg
		fi

		if [ -d "/var/db/pkg" ]; then
			if [ ! -L "/var/db/pkg" ]; then
				rm -R /var/db/pkg
				mkdir -p ${CWDIR}/system/pkg/db
				ln -vFs ${CWDIR}/system/pkg/db /var/db/pkg
			fi
		else
			mkdir -p ${CWDIR}/system/pkg/db
			ln -vFs ${CWDIR}/system/pkg/db /var/db/pkg
		fi
	fi
}

fetch_pkg() {
	if ! sysrc -f ${CWDIR}${EXTCONF} -qn LINUX_COMPAT_SUPPORT | grep -q "YES"; then
		echo "Fetching required packages."

		# Skip existing packages/ports bundled with XigmaNAS.
		#PKGLIST="#bash #ca_root_nss debootstrap #gettext-runtime glib gmp gnugrep gnugpg gnutls #indexinfo libassuan #libedit #libffi libgcrypt libgpg-error #libiconv libidn2 libksba libtasn1 libunistring libxml2 mpdecimal nettle npth p11-kit #pcre perl5 pinentry pinentry-curses #python38 #readline #sqlite3 tpm-emulator #trousers ubuntu-keyring wget"
		PKGLIST="debootstrap glib gmp gnugrep gnupg gnutls libassuan libgcrypt libgpg-error libidn2 libksba libtasn1 libunistring libxml2 mpdecimal nettle npth p11-kit perl5 pinentry pinentry-curses tpm-emulator ubuntu-keyring wget"

		for pkg in ${PKGLIST}; do
			pkg fetch -y "${pkg}" || error_notify "Error while fetching required [${pkg}] package, exiting."
		done

		extract_pkg
	fi
}

extract_pkg() {
	echo "Extracting required packages."

	if [ "${PRDPLATFORM}" = "x64-embedded" ]; then
		FILELIST=$(find "${CWDIR}/system/cache/pkg" -type f)
		LINKLIST=$(find "${CWDIR}/system/cache/pkg" -type l)
	else
		FILELIST=$(find "/var/cache/pkg" -type f)
		LINKLIST=$(find "/var/cache/pkg" -type l)
	fi

	for item in ${FILELIST}; do
		if [ -f "${item}" ]; then
			tar --exclude="+COMPACT_MANIFEST" --exclude="+MANIFEST" -xf ${item} -C ${CWDIR}/system || error_notify "Error while extracting required [${pkg}] package, exiting."
			rm -rf ${item}
		fi
	done

	# Clean leftovers pkg symlinks
	if [ "${PRDPLATFORM}" = "x64-embedded" ]; then
		for item in ${LINKLIST}; do
			if [ -L "${item}" ]; then
				rm -rf ${item}
			fi
		done
	else
		echo "Cleaning the pkg cache."
		pkg clean -y -a
	fi
}

unionfs_on() {
	if ! df | grep -q "${CWDIR}/system/usr/local"; then
		echo "Enabling UnionFS mount for ${CWDIR}/system/usr/local."
		mount_unionfs -o below ${CWDIR}/system/usr/local /usr/local
	fi
	
	if ! df | grep -q "${CWDIR}/system/var/run"; then
		echo "Enabling UnionFS mount for ${CWDIR}/system/var/run."
		mount_unionfs -o below ${CWDIR}/system/var/run /var/run
	fi
}

unionfs_off() {
	if df | grep -q "${CWDIR}/system/usr/local"; then
		echo "Disabling UnionFS mounts for ${CWDIR}/system/usr/local."
		umount -f /usr/local
	fi

	if df | grep -q "${CWDIR}/system/var/run"; then
		echo "Disabling UnionFS mounts for ${CWDIR}/system/var/run."
		umount -f /var/run
	fi
}

case "${1}" in
	fetch_pkg)
		platform_check
		fetch_pkg
	;;
	load_kmods)
		load_kmods
	;;
	unionfs_on)
		unionfs_on
	;;
	unionfs_off)
		unionfs_off
	;;
esac
