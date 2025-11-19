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

# Copyright (c) 2019-2025, José Rivera (joserprg@gmail.com).
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
	echo -e "${MSG}" >&2
	posterror_exec
	exit 1
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
		if ! sysrc -f /boot/loader.conf -qc ${_req_kmod}_load=YES; then
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
	if ! sysrc -qc linux_enable=YES; then
		sysrc linux_enable=YES
	fi
}

unload_kmods() {
	required_mods="fdescfs linprocfs linsysfs tmpfs"
	linuxarc_mods="linux linux64"

	for _req_kmod in ${required_mods}; do
		if sysrc -f /boot/loader.conf -qc ${_req_kmod}_load=YES; then
			echo "Unset kernel module: ${_req_kmod}"
			sysrc -f /boot/loader.conf -x ${_req_kmod}_load
		fi
	done

	if sysrc -qc linux_enable=YES; then
		echo "Unset linux_enable"
		sysrc -x linux_enable
	fi
}

posterror_exec() {
	# Commands to be executed post errors.
	unionfs_disable

	# Clean for stale pkg.
	if [ -d "${CWDIR}/system/All" ]; then
		rm -r ${CWDIR}/system/All
	fi
}

unionfs_disable() {
	# Check and disable uniofs mounts on error.
	unionfs_pkgoff
	unionfs_off
}

unionfs_pkgon() {
	if ! df | grep -q "${CWDIR}/system/var/db/pkg"; then
		echo "Enabling UnionFS for ${CWDIR}/system/var/db/pkg."
		mount_unionfs -o avobe ${CWDIR}/system/var/db/pkg /var/db/pkg
	fi
}

unionfs_pkgoff() {
	if df | grep -q "${CWDIR}/system/var/db/pkg"; then
		echo "Disabling UnionFS for ${CWDIR}/system/var/db/pkg."
		umount -f /var/db/pkg
	fi
}

fetch_cmd() {
	PKG_LIST="debootstrap debian-keyring"
	pkg fetch -y -d -o ${CWDIR}/system/ ${PKG_LIST}
}

fetch_pkg() {
	if [ ! -d "/var/db/pkg" ]; then
		mkdir -p "/var/db/pkg"
	fi
	if [ ! -d "${CWDIR}/system/var/db/pkg" ]; then
		mkdir -p ${CWDIR}/system/var/db/pkg
	fi

	trap "unionfs_pkgoff" 0 1 2 5 15
	unionfs_pkgon

	echo "Fetching required packages."
	# Fetch deboostrap and dependency packages.
	fetch_cmd || echo "Cleaning addon stale pkg db and retry..."
	rm -rf ${CWDIR}/system/var/db/pkg/*
	fetch_cmd || error_notify "Error while fetching packages, exiting."
	echo "Done."

	unionfs_pkgoff

	extract_pkg
}

fetch_debootstrap() {
	if ! sysrc -f ${CWDIR}${EXTCONF} -qc LINUX_COMPAT_SUPPORT=YES; then
		fetch_pkg
	fi
}

extract_pkg() {
	echo "Extracting required packages."
	FILELIST=$(find "${CWDIR}/system/All" -type f)

	for item in ${FILELIST}; do
		if [ -f "${item}" ]; then
			tar --exclude="+COMPACT_MANIFEST" --exclude="+MANIFEST" -xf ${item} -C ${CWDIR}/system || error_notify "Error while extracting required [${pkg}] package, exiting."
			rm -rf ${item}
		fi
	done

	if [ -d "${CWDIR}/system/All" ]; then
		rm -r ${CWDIR}/system/All
	fi

	if [ ! -d "${CWDIR}/templates" ]; then
		mkdir -p ${CWDIR}/templates
	fi

	if [ ! -d "${CWDIR}/system/var/run" ]; then
		mkdir -p ${CWDIR}/system/var/run
	fi

	echo "Done."
}

unionfs_on() {
	if ! df | grep -q "${CWDIR}/system/usr/local"; then
		echo "Enabling UnionFS for ${CWDIR}/system/usr/local."
		mount_unionfs -o above ${CWDIR}/system/usr/local /usr/local
	fi

	if ! df | grep -q "${CWDIR}/system/var/run"; then
		echo "Enabling UnionFS for ${CWDIR}/system/var/run."
		mount_unionfs -o avobe ${CWDIR}/system/var/run /var/run
	fi
}

unionfs_off() {
	if df | grep -q "${CWDIR}/system/usr/local"; then
		echo "Disabling UnionFS for ${CWDIR}/system/usr/local."
		umount -f /usr/local
	fi

	if df | grep -q "${CWDIR}/system/var/run"; then
		echo "Disabling UnionFS for ${CWDIR}/system/var/run."
		umount -f /var/run
	fi
}

update_debootstrap() {
	echo "Updating debootstrap..."
	unionfs_off
	fetch_pkg
}

case "${1}" in
	fetch_debootstrap)
		fetch_debootstrap
	;;
	load_kmods)
		load_kmods
	;;
	unload_kmods)
		unload_kmods
	;;
	unionfs_on)
		unionfs_on
	;;
	unionfs_off)
		unionfs_off
	;;
	update_debootstrap)
		update_debootstrap
	;;
esac
