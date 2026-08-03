# SPDX-FileCopyrightText: Opinsys Oy <dev@opinsys.fi>
# SPDX-License-Identifier: AGPL-3.0-or-later

app_name=groupsharemachine
build_dir=$(CURDIR)/build
sign_dir=$(build_dir)/sign
cert_dir=$(HOME)/.nextcloud/certificates
docker_container=master_nextcloud_1
test_container=master_stable33_1
container_app_path=/var/www/html/apps-shared/$(app_name)
shared_dir=$(HOME)/dev/nextcloud/nextcloud-docker-dev/data/shared

all: build

.PHONY: build
build: clean package

.PHONY: package
package:
	mkdir -p $(sign_dir)/$(app_name)
	cp -r \
		appinfo \
		img \
		lib \
		LICENSE \
		LICENSES \
		README.md \
		$(sign_dir)/$(app_name)
	tar czf $(build_dir)/$(app_name).tar.gz \
		-C $(sign_dir) $(app_name)

.PHONY: sign
sign: package
	mkdir -p $(shared_dir)/sign
	cp -r $(sign_dir)/$(app_name) $(shared_dir)/sign/
	cp $(cert_dir)/$(app_name).key $(cert_dir)/$(app_name).crt $(shared_dir)/sign/
	chmod 644 $(shared_dir)/sign/$(app_name).key
	chmod -R a+rwX $(shared_dir)/sign/$(app_name)
	docker exec -u www-data $(docker_container) php occ integrity:sign-app \
		--privateKey=/shared/sign/$(app_name).key \
		--certificate=/shared/sign/$(app_name).crt \
		--path=/shared/sign/$(app_name)
	cp -r $(shared_dir)/sign/$(app_name) $(sign_dir)/
	rm -rf $(shared_dir)/sign
	tar czf $(build_dir)/$(app_name).tar.gz \
		-C $(sign_dir) $(app_name)

.PHONY: test
test:
	docker exec -u www-data $(test_container) bash -c \
		"cd $(container_app_path) && vendor/bin/phpunit -c tests/phpunit.xml --no-coverage"

.PHONY: psalm
psalm:
	composer psalm

.PHONY: clean
clean:
	rm -rf $(build_dir)
