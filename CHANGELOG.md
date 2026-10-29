# Changelog

Metricove follows Semantic Versioning. Before 1.0.0, releases use `0.MINOR.PATCH`: features and breaking changes increment MINOR; compatible fixes increment PATCH. Release Please opens reviewable release pull requests from Conventional Commit titles and commit subjects, including the dated changelog section and version updates. The first stable release will use `1.0.0` once the public API and installation behavior are considered stable. See [the release guide](docs/releases.md) for release and image procedures.

## [0.11.0](https://github.com/marcel-breuer/metricove/compare/metricove-v0.10.1...metricove-v0.11.0) (2026-10-29)


### Features

* **receipts:** add household sharing and archive filters ([fbff1f8](https://github.com/marcel-breuer/metricove/commit/fbff1f8553d314afd6c9e7687a58ec7dcf28d023))
* **receipts:** add local OCR invoice scanning ([e84d566](https://github.com/marcel-breuer/metricove/commit/e84d5660bd120e6bfbc09ef23fafe3998480b8f5))
* **receipts:** add local OCR scanner and household sharing ([44787f3](https://github.com/marcel-breuer/metricove/commit/44787f31adb9a7258973a88ee73fcc81d85b97eb))
* **receipts:** complete archive linking and fuel details ([8c2c4a0](https://github.com/marcel-breuer/metricove/commit/8c2c4a0a24e1492235167015dc0b2a18ee992ad2))


### Bug Fixes

* **deps:** update Symfony MIME security patch ([0321c39](https://github.com/marcel-breuer/metricove/commit/0321c39df8b0d2dac23c8dafd5b8521bcc3e1f85))

## [0.10.1](https://github.com/marcel-breuer/metricove/compare/metricove-v0.10.0...metricove-v0.10.1) (2026-10-28)


### Bug Fixes

* make profile page state reactive ([7e64c2d](https://github.com/marcel-breuer/metricove/commit/7e64c2dd6d1e65bdd0de58d5ea8852334c9a3215))
* **profile:** restore profile state reactivity ([fd833ac](https://github.com/marcel-breuer/metricove/commit/fd833ac3d98625a267bb7c3d5e37fe99679eb927))

## [0.10.0](https://github.com/marcel-breuer/metricove/compare/metricove-v0.9.0...metricove-v0.10.0) (2026-10-26)


### Features

* **vehicles:** lazy-load odometer history ([#149](https://github.com/marcel-breuer/metricove/issues/149)) ([7413c31](https://github.com/marcel-breuer/metricove/commit/7413c31d4f81ccea7cf849eb42876ebc2ddea229))
* **vehicles:** paginate odometer history ([be4f240](https://github.com/marcel-breuer/metricove/commit/be4f2404237eb1009c1fce0147ececd2281ecd77))


### Bug Fixes

* **i18n:** translate odometer history errors ([b1f15f7](https://github.com/marcel-breuer/metricove/commit/b1f15f77ca9408564e8c1aabe012cebe2815d840))
* **vehicles:** preserve current main changes ([410c1d8](https://github.com/marcel-breuer/metricove/commit/410c1d8e19e8f9a60183ef8aa91e07cd73fee15b))
* **vehicles:** preserve full translations in history page ([67feadc](https://github.com/marcel-breuer/metricove/commit/67feadcc31f144d6ffab1046c6d3f3d3cb7fc150))
* **vehicles:** retain full German translations ([fdf533d](https://github.com/marcel-breuer/metricove/commit/fdf533d6d74501c3a93c576e16dd931e10d1c4cf))

## [0.9.0](https://github.com/marcel-breuer/metricove/compare/metricove-v0.8.0...metricove-v0.9.0) (2026-10-26)


### Features

* **tracking:** lazy-load history and add global create action ([#144](https://github.com/marcel-breuer/metricove/issues/144)) ([17cca79](https://github.com/marcel-breuer/metricove/commit/17cca7917b084376c4f889d2c78ebfc08b9b5ee6))
* **tracking:** lazy-load history and add global create action ([#144](https://github.com/marcel-breuer/metricove/issues/144)) ([1092398](https://github.com/marcel-breuer/metricove/commit/109239844428cded5db6e2a5a08656a7f3e8b683))
* **tracking:** lazy-load history and add global create action ([#146](https://github.com/marcel-breuer/metricove/issues/146)) ([17cca79](https://github.com/marcel-breuer/metricove/commit/17cca7917b084376c4f889d2c78ebfc08b9b5ee6))


### Bug Fixes

* **i18n:** translate all module dialogs and confirmation actions ([3f92c4d](https://github.com/marcel-breuer/metricove/commit/3f92c4dc0210cf26298d0eafdca10e3adf77a081))
* **i18n:** translate all module dialogs and confirmation actions ([220213f](https://github.com/marcel-breuer/metricove/commit/220213f52ba863e5431d530226a6254b92e31e76))


### Documentation

* **tracking:** clarify create permission enforcement ([#144](https://github.com/marcel-breuer/metricove/issues/144)) ([06cdc0d](https://github.com/marcel-breuer/metricove/commit/06cdc0d2ca1596463d06ad2d571b8d87bac8f0cc))
* **tracking:** restore complete module contract ([#144](https://github.com/marcel-breuer/metricove/issues/144)) ([1d03a07](https://github.com/marcel-breuer/metricove/commit/1d03a07ea004591f591c507a83a53f3106e91b9b))

## [0.8.0](https://github.com/marcel-breuer/metricove/compare/metricove-v0.7.1...metricove-v0.8.0) (2026-10-26)


### Features

* **security:** add admin-managed IP allowlist ([557966e](https://github.com/marcel-breuer/metricove/commit/557966eb8a9e479d083ba35e38d7b7f8c2cdbef1))
* **security:** add admin-managed IP allowlist ([8bb6659](https://github.com/marcel-breuer/metricove/commit/8bb66594dded30440b35bd14ae44642c92701f0a))

## [0.7.1](https://github.com/marcel-breuer/metricove/compare/metricove-v0.7.0...metricove-v0.7.1) (2026-10-24)


### Bug Fixes

* **web:** guard empty meal plan dates ([a8c3807](https://github.com/marcel-breuer/metricove/commit/a8c3807b45a3581e2a09a36706627bfe7ee8ea73))
* **web:** prevent Meal Planning SSR 500 ([10634bf](https://github.com/marcel-breuer/metricove/commit/10634bf0f45a60bb20cb144ed671460603446352))

## [0.7.0](https://github.com/marcel-breuer/metricove/compare/metricove-v0.6.0...metricove-v0.7.0) (2026-10-24)


### Features

* **recipes:** import recipes from URLs and PDFs ([feaf3e6](https://github.com/marcel-breuer/metricove/commit/feaf3e67843197b6242205792ef3dbb005356f01)), closes [#132](https://github.com/marcel-breuer/metricove/issues/132)
* **recipes:** import recipes from URLs and PDFs ([d1923bf](https://github.com/marcel-breuer/metricove/commit/d1923bf9a5573800219ac2bda9ff0c9d5c24ab6c))


### Bug Fixes

* **recipes:** satisfy static analysis for recipe import ([9dbee09](https://github.com/marcel-breuer/metricove/commit/9dbee09f909f3ae662e2b19782fcc72ca4c68718))
* **web:** translate recipe import workflow ([dce17bf](https://github.com/marcel-breuer/metricove/commit/dce17bf1012acc866527856b267de33d7c20d134)), closes [#134](https://github.com/marcel-breuer/metricove/issues/134)
* **web:** translate recipe import workflow ([db3f6ed](https://github.com/marcel-breuer/metricove/commit/db3f6ed08cdcb904de137a2259e04e4e022a889a))

## [0.6.0](https://github.com/marcel-breuer/metricove/compare/metricove-v0.5.0...metricove-v0.6.0) (2026-10-24)


### Features

* **core:** add permission-aware household reminders ([a0a7e85](https://github.com/marcel-breuer/metricove/commit/a0a7e85c9d13b9670e8bc007b967270071a526bd))
* **core:** refresh reminders on current main ([0d2f771](https://github.com/marcel-breuer/metricove/commit/0d2f771ef7a67de579a0807b7619f9bdb7a7e373))
* **import:** import finance transactions from CSV and CAMT.053 ([3f32a83](https://github.com/marcel-breuer/metricove/commit/3f32a83b065fa6fe76074da753f4e97e63aec4d7))
* **import:** refresh finance import on current main ([8325292](https://github.com/marcel-breuer/metricove/commit/8325292be2a1013a3634c8c42bb854ca96da3b94))
* **tasks:** add assigned recurring household tasks ([0d0182b](https://github.com/marcel-breuer/metricove/commit/0d0182bd5111c8aa99c71c0871c6a92d1a56138e))
* **tasks:** add assigned recurring household tasks ([2114955](https://github.com/marcel-breuer/metricove/commit/2114955f48dca449fd148409ad5247216c6cb797))
* **tracking:** add deletion and paged history ([6a63631](https://github.com/marcel-breuer/metricove/commit/6a636319d4f0b9aaf646ba2cbd55d9f394a767a8))
* **tracking:** add deletion and paged history ([#126](https://github.com/marcel-breuer/metricove/issues/126)) ([6a63631](https://github.com/marcel-breuer/metricove/commit/6a636319d4f0b9aaf646ba2cbd55d9f394a767a8))
* **tracking:** add deletion and paged history ([#126](https://github.com/marcel-breuer/metricove/issues/126)) ([9bedc37](https://github.com/marcel-breuer/metricove/commit/9bedc370817fe6e367a630e4fabe70b498b649a3))
* **web:** complete CRUD modal conversion ([49e312a](https://github.com/marcel-breuer/metricove/commit/49e312a20c044fedffd07ba923a6caf4a682a34a)), closes [#130](https://github.com/marcel-breuer/metricove/issues/130)
* **web:** finish modal conversion for CRUD workflows ([d0ddd4c](https://github.com/marcel-breuer/metricove/commit/d0ddd4cdbcccd328afab74b6bd355d66e42adff7))
* **web:** open CRUD workflows in modals ([0d9d5e7](https://github.com/marcel-breuer/metricove/commit/0d9d5e7aa46db804a74903d06afffc59e1d3e24a))
* **web:** open CRUD workflows in modals ([0d9d5e7](https://github.com/marcel-breuer/metricove/commit/0d9d5e7aa46db804a74903d06afffc59e1d3e24a))
* **web:** open CRUD workflows in modals ([b2bdd03](https://github.com/marcel-breuer/metricove/commit/b2bdd03a52c88ccbd99f31302f6a264ecffe09ed))


### Bug Fixes

* **tracking:** keep paged history consumers compatible ([c6914f7](https://github.com/marcel-breuer/metricove/commit/c6914f75f97ed6846cc21e2b8be443019182adc4))

## [0.5.0](https://github.com/marcel-breuer/metricove/compare/metricove-v0.4.0...metricove-v0.5.0) (2026-10-23)


### Features

* **i18n:** localize route headings and auto-merge releases ([47aa17c](https://github.com/marcel-breuer/metricove/commit/47aa17c4822143f938e61d2402a78888358119cb))
* **i18n:** translate all web route copy ([d5cf183](https://github.com/marcel-breuer/metricove/commit/d5cf1833e8348edb2b3a3b87161509d9c1b294ba))
* **i18n:** translate all web route copy ([2c0e2aa](https://github.com/marcel-breuer/metricove/commit/2c0e2aac7a2fb0229cd9d170434a28424eeba7f2))


### Bug Fixes

* **release:** merge release PRs after checks pass ([4da802a](https://github.com/marcel-breuer/metricove/commit/4da802ad13ba39ebe550b88a1d0ddc8d910e49b9))
* **release:** merge release PRs after checks pass ([d17753f](https://github.com/marcel-breuer/metricove/commit/d17753f1b100d0c37d0e51afa75c356d551a1125))

## [0.4.0](https://github.com/marcel-breuer/metricove/compare/metricove-v0.3.0...metricove-v0.4.0) (2026-10-22)


### Features

* **ai:** make assistant a permission-aware core feature ([#103](https://github.com/marcel-breuer/metricove/issues/103)) ([2317766](https://github.com/marcel-breuer/metricove/commit/23177662824855365d842990cee6107b4a1e6960))
* **ai:** make assistant a permission-aware core feature ([#103](https://github.com/marcel-breuer/metricove/issues/103)) ([acf5182](https://github.com/marcel-breuer/metricove/commit/acf51826e59150bbe2d07e6f2ca78ce0b5f29625))
* **i18n:** add user locale preferences and MCP docs ([567fb1a](https://github.com/marcel-breuer/metricove/commit/567fb1ab9623952e4b8ff3209207d2095961fb62))
* **i18n:** add user locale preferences and MCP docs ([567fb1a](https://github.com/marcel-breuer/metricove/commit/567fb1ab9623952e4b8ff3209207d2095961fb62))
* **i18n:** add user locale preferences and MCP docs ([6245561](https://github.com/marcel-breuer/metricove/commit/6245561bf2451cf3c218a0e1fb7fee0aa412f493))
* **profile:** allow members to change their password ([#104](https://github.com/marcel-breuer/metricove/issues/104)) ([99536c5](https://github.com/marcel-breuer/metricove/commit/99536c5cb483b8d7f449f2b9fbcd9f9fa668bb13))
* **profile:** allow members to change their password ([#104](https://github.com/marcel-breuer/metricove/issues/104)) ([e00250d](https://github.com/marcel-breuer/metricove/commit/e00250d0402917d28e7cacc5d3062c6b25c75491))
* **provider:** add Claude, Gemini and OpenRouter ([#109](https://github.com/marcel-breuer/metricove/issues/109)) ([88cb555](https://github.com/marcel-breuer/metricove/commit/88cb555fca6e4304b06990780befeba6ac7b88eb))
* **provider:** add selectable providers ([#109](https://github.com/marcel-breuer/metricove/issues/109)) ([cae97d7](https://github.com/marcel-breuer/metricove/commit/cae97d7c199c53d69f78fab4347855232e5505a0))
* **search:** add household search from header ([#107](https://github.com/marcel-breuer/metricove/issues/107)) ([f0ba911](https://github.com/marcel-breuer/metricove/commit/f0ba91109b49fd060b91b932e9b61aa43d50c04f))
* **search:** replace Quick Add with household search ([#107](https://github.com/marcel-breuer/metricove/issues/107)) ([1186815](https://github.com/marcel-breuer/metricove/commit/118681579361fbb32871d3f80d3f06e9f32cc0f2))
* **web:** add bulk module management toggle ([b51db02](https://github.com/marcel-breuer/metricove/commit/b51db02a4d79800daa3d02bc5c352a778a733a52))
* **web:** add bulk module toggle ([#99](https://github.com/marcel-breuer/metricove/issues/99)) ([b7d0c31](https://github.com/marcel-breuer/metricove/commit/b7d0c3185878dc95fe840704604f571e16aa950e))


### Bug Fixes

* **ai:** satisfy settings configuration analysis ([#103](https://github.com/marcel-breuer/metricove/issues/103)) ([d7d5867](https://github.com/marcel-breuer/metricove/commit/d7d58674855c4abddc85fd9545f2eae898f0b83a))
* **ci:** resolve frontend typing and PHP formatting ([446effe](https://github.com/marcel-breuer/metricove/commit/446effe3a2b2e81b6127337e4dd19e082434ff0b))
* **coolify:** allow runtime-only secrets during compose build ([750bcb2](https://github.com/marcel-breuer/metricove/commit/750bcb29a34537e418401a76b5fa12ed72808d1f))
* **coolify:** allow runtime-only secrets during Compose build ([3e59191](https://github.com/marcel-breuer/metricove/commit/3e59191eaa66aa13b0b6fc7db046a5fea89856ad))
* **profile:** key password limiter by authenticated member ([#104](https://github.com/marcel-breuer/metricove/issues/104)) ([d3f0660](https://github.com/marcel-breuer/metricove/commit/d3f06603eb2cef7a069d9ca077ae09de8b471d09))
* **provider:** correct tool argument validation ([#109](https://github.com/marcel-breuer/metricove/issues/109)) ([6e5405f](https://github.com/marcel-breuer/metricove/commit/6e5405f49f39c1adcf8c64b5829e4a6eb2b0d3bb))
* **web:** add accessible registration guidance ([f5e865a](https://github.com/marcel-breuer/metricove/commit/f5e865aaf89a3828620eaf1d872676c9a250f6e4))
* **web:** link registration form guidance ([#101](https://github.com/marcel-breuer/metricove/issues/101)) ([7a8446d](https://github.com/marcel-breuer/metricove/commit/7a8446d10aab34dc1c2c951c7e66f621ef540ad0))


### Documentation

* **config:** clarify SMTP placeholders ([#111](https://github.com/marcel-breuer/metricove/issues/111)) ([c0d4ef7](https://github.com/marcel-breuer/metricove/commit/c0d4ef7b0f0652d394d2a4e9dd2c0bca9d52f996))
* **config:** clarify SMTP placeholders ([#111](https://github.com/marcel-breuer/metricove/issues/111)) ([9271150](https://github.com/marcel-breuer/metricove/commit/92711503e98e996fc673de56cf7809d6d332f59c))
* **config:** explain SMTP host reachability ([#111](https://github.com/marcel-breuer/metricove/issues/111)) ([6e23684](https://github.com/marcel-breuer/metricove/commit/6e23684de8dc211015fded9e0c44b64f72212812))

## [0.3.0](https://github.com/marcel-breuer/metricove/compare/metricove-v0.2.1...metricove-v0.3.0) (2026-10-21)


### Features

* add Docker Compose runtime ([5799516](https://github.com/marcel-breuer/metricove/commit/5799516dc1299058acfb03b83df3d68f4e09f71e))
* add module-wide grants and theme preferences ([d71814b](https://github.com/marcel-breuer/metricove/commit/d71814b8227a0652b937c980e2ff3c9170a6afe0))
* add per-member module permissions and record privacy ([387f360](https://github.com/marcel-breuer/metricove/commit/387f360f89f79d7af3f71a9607e8b444a9d31852))
* add production Docker Compose runtime ([62bcf61](https://github.com/marcel-breuer/metricove/commit/62bcf61533c5c9a64f98d148c19fb262081971b2))
* **ai:** add authenticated owner-scoped context CRUD ([#56](https://github.com/marcel-breuer/metricove/issues/56)) ([07549e2](https://github.com/marcel-breuer/metricove/commit/07549e2a00b4d803073cd1dd832b6142e2fc99de))
* **ai:** add context entry model ([#56](https://github.com/marcel-breuer/metricove/issues/56)) ([31de15c](https://github.com/marcel-breuer/metricove/commit/31de15cdfdbfd87b811b0dd2c05dd806b2f14eb8))
* **ai:** add permission-aware read-only assistant ([1095088](https://github.com/marcel-breuer/metricove/commit/1095088ce8f9231f7fec4a85af6ea146d4c1c403))
* **ai:** add persistent private context ([5e1533a](https://github.com/marcel-breuer/metricove/commit/5e1533ada8aa7b9439d502f38d08c01e4c45ce3d))
* **ai:** add private persistent AI context ([#56](https://github.com/marcel-breuer/metricove/issues/56)) ([7a7ea18](https://github.com/marcel-breuer/metricove/commit/7a7ea1849dd7f9ad6e238b8733c18423edb37890))
* **ai:** add scoped MCP access ([16bd4c5](https://github.com/marcel-breuer/metricove/commit/16bd4c504a13c8d35f616ae8f5332ae1099d92f6))
* **ai:** add user-scoped persistent context storage ([#56](https://github.com/marcel-breuer/metricove/issues/56)) ([7ccda91](https://github.com/marcel-breuer/metricove/commit/7ccda915a52d542525f4a2f54d6259f48aa9db44))
* **ai:** wire persistent context into API and assistant ([#56](https://github.com/marcel-breuer/metricove/issues/56)) ([5ccd0df](https://github.com/marcel-breuer/metricove/commit/5ccd0dfed16456370994a6cf0d63b03863935416))
* **ai:** wire persistent context into API and assistant ([#56](https://github.com/marcel-breuer/metricove/issues/56)) ([06a7f86](https://github.com/marcel-breuer/metricove/commit/06a7f866b76caa7cb6685f3f24b92a972098d672))
* **analytics:** add private cross-module dashboards ([cc6bf75](https://github.com/marcel-breuer/metricove/commit/cc6bf75a7f9dfcbdab33bff841f3bd94ceb69b94))
* **analytics:** add private cross-module dashboards ([65987d2](https://github.com/marcel-breuer/metricove/commit/65987d21b192065881f8018afc29eb84117b33d3))
* bootstrap Laravel API and SvelteKit web app ([272d010](https://github.com/marcel-breuer/metricove/commit/272d010bdff06c3a75ce258c3a38c146bc46fd8c))
* bootstrap Laravel API and SvelteKit web app ([79b7eb9](https://github.com/marcel-breuer/metricove/commit/79b7eb9acbf1bfbb08d8c67dd8cdd0bbcd3d10a2))
* bootstrap Laravel API and SvelteKit web app ([#30](https://github.com/marcel-breuer/metricove/issues/30)) ([272d010](https://github.com/marcel-breuer/metricove/commit/272d010bdff06c3a75ce258c3a38c146bc46fd8c))
* **core:** add admin email invitations ([2da9204](https://github.com/marcel-breuer/metricove/commit/2da920407122957d260e062948ca5490f8c0fe42))
* **core:** add admin email invitations ([#7](https://github.com/marcel-breuer/metricove/issues/7)) ([83ef207](https://github.com/marcel-breuer/metricove/commit/83ef20771f6e193cd661b8017510d89c5b0fb2a6))
* **core:** add atomic first-run setup ([#5](https://github.com/marcel-breuer/metricove/issues/5)) ([edb31d8](https://github.com/marcel-breuer/metricove/commit/edb31d80ecbc74f13282ac2b0c419ac58f397347))
* **core:** add atomic first-run setup ([#5](https://github.com/marcel-breuer/metricove/issues/5)) ([a0761c3](https://github.com/marcel-breuer/metricove/commit/a0761c32379b69fdddd9bf5e4a9b4d712cb97258))
* **core:** add module registry and activation controls ([c07d23c](https://github.com/marcel-breuer/metricove/commit/c07d23c9c57b3e2a1c557415206180bfa785d2a4))
* **core:** add per-member module permissions and record privacy ([5dc9def](https://github.com/marcel-breuer/metricove/commit/5dc9def0ef80727bea9effaa20f17f915192c072)), closes [#10](https://github.com/marcel-breuer/metricove/issues/10)
* **core:** add session auth and member profiles ([533a8b4](https://github.com/marcel-breuer/metricove/commit/533a8b4c911f253581ff41c54a1320c26c4816bd))
* **core:** add session authentication, profiles and password recovery ([798f5bf](https://github.com/marcel-breuer/metricove/commit/798f5bf8211b078645955326783d4dc2ce62f15e))
* **core:** protect last active administrator ([#8](https://github.com/marcel-breuer/metricove/issues/8)) ([8336f24](https://github.com/marcel-breuer/metricove/commit/8336f24a50f6cefa0800362c33fd09c78bb0cbed))
* **finance:** add transactions and monthly budgets ([b434a70](https://github.com/marcel-breuer/metricove/commit/b434a7045e169a8eb3be32c669fd75d2c3bda77c))
* format measurements by display unit ([bc76d73](https://github.com/marcel-breuer/metricove/commit/bc76d73d95e3ad4a5a5ccf37264049fcdbeb6b37))
* **import:** add mapped CSV flows and local OCR ([d7ea9a5](https://github.com/marcel-breuer/metricove/commit/d7ea9a5ebaf1b3305c6c1f82fb06590f71d9671e))
* **import:** mapped CSV flows and local OCR ([9c2e8cf](https://github.com/marcel-breuer/metricove/commit/9c2e8cf4960145985e778a68668574d09fe4f58f))
* **mcp:** support non-expiring external tokens ([ec3505d](https://github.com/marcel-breuer/metricove/commit/ec3505d493d8980242a7dc5246dd2b0793210afe))
* **mcp:** support non-expiring external tokens ([83dff38](https://github.com/marcel-breuer/metricove/commit/83dff3835ef9229964f84b5ee75ac51db9427151)), closes [#71](https://github.com/marcel-breuer/metricove/issues/71)
* **meal-planning:** add editable weekly meal plans ([f4bf13e](https://github.com/marcel-breuer/metricove/commit/f4bf13e523167f7563db76abeb4707ecaef84f0b))
* **ops:** add backup/restore and privacy hardening ([b34364f](https://github.com/marcel-breuer/metricove/commit/b34364f063d551508053449376c046dd22c3de70))
* **ops:** add verified backup and restore workflow ([34971f1](https://github.com/marcel-breuer/metricove/commit/34971f16a88dd271f6ee6fb325808d0e6a0f4191))
* **pantry:** track household stock and best-before dates ([db53d33](https://github.com/marcel-breuer/metricove/commit/db53d33de1ebb10aacd755ec823b7b4e9af73153))
* **recipes:** manage private and household recipes ([9358e59](https://github.com/marcel-breuer/metricove/commit/9358e59fb36d6a15bf248250cc24a3a41817b295))
* **release:** automate semantic version releases ([#75](https://github.com/marcel-breuer/metricove/issues/75)) ([9328cd8](https://github.com/marcel-breuer/metricove/commit/9328cd82f6f08fce3fb4022e0b3bcdd917c1faed))
* **release:** automate SemVer releases ([c414311](https://github.com/marcel-breuer/metricove/commit/c41431160e18174ad3f92a6829e0181d9a3ee625))
* **release:** publish immutable multi-arch images ([c18df99](https://github.com/marcel-breuer/metricove/commit/c18df9902530b1f505c322a027c7d88175154b17))
* **release:** publish immutable multi-arch images ([#53](https://github.com/marcel-breuer/metricove/issues/53)) ([8895fbb](https://github.com/marcel-breuer/metricove/commit/8895fbb23b2ed76c4e98aab811edaab41c4b833d)), closes [#26](https://github.com/marcel-breuer/metricove/issues/26)
* **release:** validate conventional release metadata ([9fc48ae](https://github.com/marcel-breuer/metricove/commit/9fc48ae261294b258618d556dcdb8022ef27f739))
* **shopping:** add collaborative household shopping lists ([550a868](https://github.com/marcel-breuer/metricove/commit/550a8687ef4d9b7136087341a0e9923d0e5692fa))
* **shopping:** add opt-in meal plan suggestions ([#20](https://github.com/marcel-breuer/metricove/issues/20)) ([7ef05ef](https://github.com/marcel-breuer/metricove/commit/7ef05efbfd55f25ae5801dd37cbd2b4fc8b9bd84))
* **shopping:** add opt-in meal plan suggestions ([#20](https://github.com/marcel-breuer/metricove/issues/20)) ([10532ea](https://github.com/marcel-breuer/metricove/commit/10532eaafbcf9a156de35523885597fd37d61d7e))
* **tracking:** add custom trackers and measurement history ([5dec81b](https://github.com/marcel-breuer/metricove/commit/5dec81b45c67de8a2b43d811d589a7e9fc83a303))
* **tracking:** custom trackers and measurement history ([#39](https://github.com/marcel-breuer/metricove/issues/39)) ([cb85570](https://github.com/marcel-breuer/metricove/commit/cb85570defbf32bba0ce96f148b3e24cb2550c7e)), closes [#12](https://github.com/marcel-breuer/metricove/issues/12)
* **ui:** unify module grants, measurement formatting, and themes ([64ebbe5](https://github.com/marcel-breuer/metricove/commit/64ebbe5d6c146c8c1067aa4b250e0f59fc0b2296))
* **utilities:** add meter readings and usage trends ([220c58c](https://github.com/marcel-breuer/metricove/commit/220c58ce2d9061d6b30ea6de2b26e07272b118a1)), closes [#13](https://github.com/marcel-breuer/metricove/issues/13)
* **utilities:** add meter readings and usage trends ([0764c71](https://github.com/marcel-breuer/metricove/commit/0764c71c66580a397b5f838f08828c6f49c0d983))
* **vehicles:** add fleet and operating records ([e07b1c0](https://github.com/marcel-breuer/metricove/commit/e07b1c00bfd82dcdfa7c69da4327bf0b8662174c)), closes [#14](https://github.com/marcel-breuer/metricove/issues/14)
* **vehicles:** add fleet and operating records ([f9c75fe](https://github.com/marcel-breuer/metricove/commit/f9c75fef9a73dac1c51c31392e4807c0d0459b40))
* **web:** add permission-aware responsive app shell ([ad34b3d](https://github.com/marcel-breuer/metricove/commit/ad34b3df768cf0a3d79278309cd16fcabcb3fdda)), closes [#11](https://github.com/marcel-breuer/metricove/issues/11)
* **web:** align first-admin setup with sign-in ([72f5dca](https://github.com/marcel-breuer/metricove/commit/72f5dca7586dfdfa24804b4f070c1516c3cadec7))
* **web:** align first-admin setup with sign-in ([fb7a5de](https://github.com/marcel-breuer/metricove/commit/fb7a5de082dd5e777570c475beeb56c8b834669e))
* **web:** guide new admins to household features ([3aec22f](https://github.com/marcel-breuer/metricove/commit/3aec22ff266696065108fbdf87fc902ebd536741))
* **web:** guide new admins to household features ([f30b65e](https://github.com/marcel-breuer/metricove/commit/f30b65e645e31299d93ccf33792fd68627c206ea))
* **web:** improve login screen usability ([#64](https://github.com/marcel-breuer/metricove/issues/64)) ([05a92f4](https://github.com/marcel-breuer/metricove/commit/05a92f4f1de610e1585e80576d38460a3c81b242)), closes [#63](https://github.com/marcel-breuer/metricove/issues/63)
* **web:** polish login screen ([#63](https://github.com/marcel-breuer/metricove/issues/63)) ([b461e8f](https://github.com/marcel-breuer/metricove/commit/b461e8f5d82b461057886bd49fc0286678bdc612))


### Bug Fixes

* **ai:** keep saved context outside system prompt ([87faf79](https://github.com/marcel-breuer/metricove/commit/87faf799b29c20ae301f1a475ac08e309cce4fbf))
* **api:** preserve initial setup when seeding ([f182e1c](https://github.com/marcel-breuer/metricove/commit/f182e1c4d556c51bbabbbd9397230cb76e605937))
* **api:** preserve initial setup when seeding ([3a91396](https://github.com/marcel-breuer/metricove/commit/3a91396f3d0ecf3cb5f11982b08cc02eed8bfe3c))
* **ci:** pass compose timeout to down command ([a6e7706](https://github.com/marcel-breuer/metricove/commit/a6e770695a3ac285bd2d830b23c04eefd30f793e))
* **compose:** avoid pulling local API image in Coolify ([decf2e7](https://github.com/marcel-breuer/metricove/commit/decf2e7b53c5af07124d379e28e4295e0028662c))
* **compose:** mark runtime API image consumers buildable ([29499d5](https://github.com/marcel-breuer/metricove/commit/29499d564dc6e8036d478384f4b5aae0798854ac))
* **compose:** serialize app storage initialization ([5a7915e](https://github.com/marcel-breuer/metricove/commit/5a7915e95e690cc11f66afe1dc9b98ba6eeee7f1))
* **compose:** serialize app storage initialization ([7c36389](https://github.com/marcel-breuer/metricove/commit/7c36389008be3ab552b5b6d3bde6d2f87bfd9090))
* **dependabot:** align paths and weekly review schedule ([cacb338](https://github.com/marcel-breuer/metricove/commit/cacb33812aa6ff6bfa3c45fb2bdff4b7794cb779))
* **dependabot:** move pull request limit to group ([875f824](https://github.com/marcel-breuer/metricove/commit/875f824de81cde21ff45bc9ff16e593893795ccb))
* **deps:** keep PostgreSQL 17 for volume compatibility ([3ee4c05](https://github.com/marcel-breuer/metricove/commit/3ee4c05120d16ed1adbb27cc0318632172f080d6))
* **deps:** retain TypeScript 6 for Svelte toolchain ([4b47169](https://github.com/marcel-breuer/metricove/commit/4b471690cc1da5537bf97159959b248ac7be9051))
* **docker:** pass AI context retention setting ([02973c7](https://github.com/marcel-breuer/metricove/commit/02973c7462568ab6f76d50ef495abd3260ab9618))
* ignore incompatible TypeScript major updates ([56569aa](https://github.com/marcel-breuer/metricove/commit/56569aac75e1666ff46c27224488a95a1f2e168e))
* **ops:** refresh gateway after recovery ([b5ca7c4](https://github.com/marcel-breuer/metricove/commit/b5ca7c467198902a71927b8cec490a408c4880db))
* patch critical PHP security vulnerabilities ([a56a64d](https://github.com/marcel-breuer/metricove/commit/a56a64d5689899ea71f6bee9aea3369c7d865f08))
* patch critical PHP security vulnerabilities ([36f7e7a](https://github.com/marcel-breuer/metricove/commit/36f7e7aabe8be502b023db8c13bebfa7f127cfd5))
* prevent incompatible TypeScript major updates ([0cd390c](https://github.com/marcel-breuer/metricove/commit/0cd390c0b0561897a417527a1e5188da2c81da96))
* **release:** allow Release Please to tag merged releases ([081b2f7](https://github.com/marcel-breuer/metricove/commit/081b2f7a92381ab8b5831f495774e9bdf02a61d7))
* **release:** allow Release Please to tag merged releases ([1cccec7](https://github.com/marcel-breuer/metricove/commit/1cccec7c9ff0c5c3a53e145d484f084704e64a78))
* **release:** use configured workflow token ([9dc1274](https://github.com/marcel-breuer/metricove/commit/9dc1274f72307cdb8283314e9ddc9e2c015bb8f1))
* **release:** use configured workflow token ([#83](https://github.com/marcel-breuer/metricove/issues/83)) ([0cae5c6](https://github.com/marcel-breuer/metricove/commit/0cae5c6b67769a2a98a1a1faaa846f47540acf85))
* restore Composer lockfile blob ([863325e](https://github.com/marcel-breuer/metricove/commit/863325e1af4430a922104046c9928aa36a7c8f1e))
* **test:** serialize Compose storage initialization ([ca4ba03](https://github.com/marcel-breuer/metricove/commit/ca4ba0392190e4c5b297719bea02cc9894f569ff))
* **tracking:** preserve local measurement timestamps ([63db709](https://github.com/marcel-breuer/metricove/commit/63db7092867bdccc7a02f6a1790ebf06edd7f7c2))
* **ui:** format displayed numbers by unit ([79ba09b](https://github.com/marcel-breuer/metricove/commit/79ba09b7b3d86e813725abca803103162fb795d3))
* **ui:** format numeric values consistently ([4469219](https://github.com/marcel-breuer/metricove/commit/4469219374723b7c200d52736f037a2e5224337e))
* use floating PHP 8.5 image tags ([907a019](https://github.com/marcel-breuer/metricove/commit/907a0191b2ea8717ef1f260a5bc18906766c15f9))
* use released PHP security image tags ([265c786](https://github.com/marcel-breuer/metricove/commit/265c786100635904d05d61ce1ea82b7a475aaf43))
* **web:** redirect signed-out home visits to sign-in ([1941dd9](https://github.com/marcel-breuer/metricove/commit/1941dd99242124fa8b134560d85e01d7d4d4260d))
* **web:** redirect signed-out home visits to sign-in ([e61f8c9](https://github.com/marcel-breuer/metricove/commit/e61f8c945ba2bdcb116ae3a13a5fac087f19923f))

## [0.2.1](https://github.com/marcel-breuer/metricove/compare/metricove-v0.2.0...metricove-v0.2.1) (2026-10-21)


### Bug Fixes

* **compose:** serialize app storage initialization ([5a7915e](https://github.com/marcel-breuer/metricove/commit/5a7915e95e690cc11f66afe1dc9b98ba6eeee7f1))
* **compose:** serialize app storage initialization ([7c36389](https://github.com/marcel-breuer/metricove/commit/7c36389008be3ab552b5b6d3bde6d2f87bfd9090))
* **release:** allow Release Please to tag merged releases ([081b2f7](https://github.com/marcel-breuer/metricove/commit/081b2f7a92381ab8b5831f495774e9bdf02a61d7))
* **release:** allow Release Please to tag merged releases ([1cccec7](https://github.com/marcel-breuer/metricove/commit/1cccec7c9ff0c5c3a53e145d484f084704e64a78))

## [0.2.0](https://github.com/marcel-breuer/metricove/compare/metricove-v0.1.0...metricove-v0.2.0) (2026-10-21)


### Features

* add Docker Compose runtime ([5799516](https://github.com/marcel-breuer/metricove/commit/5799516dc1299058acfb03b83df3d68f4e09f71e))
* add module-wide grants and theme preferences ([d71814b](https://github.com/marcel-breuer/metricove/commit/d71814b8227a0652b937c980e2ff3c9170a6afe0))
* add per-member module permissions and record privacy ([387f360](https://github.com/marcel-breuer/metricove/commit/387f360f89f79d7af3f71a9607e8b444a9d31852))
* add production Docker Compose runtime ([62bcf61](https://github.com/marcel-breuer/metricove/commit/62bcf61533c5c9a64f98d148c19fb262081971b2))
* **ai:** add authenticated owner-scoped context CRUD ([#56](https://github.com/marcel-breuer/metricove/issues/56)) ([07549e2](https://github.com/marcel-breuer/metricove/commit/07549e2a00b4d803073cd1dd832b6142e2fc99de))
* **ai:** add context entry model ([#56](https://github.com/marcel-breuer/metricove/issues/56)) ([31de15c](https://github.com/marcel-breuer/metricove/commit/31de15cdfdbfd87b811b0dd2c05dd806b2f14eb8))
* **ai:** add permission-aware read-only assistant ([1095088](https://github.com/marcel-breuer/metricove/commit/1095088ce8f9231f7fec4a85af6ea146d4c1c403))
* **ai:** add persistent private context ([5e1533a](https://github.com/marcel-breuer/metricove/commit/5e1533ada8aa7b9439d502f38d08c01e4c45ce3d))
* **ai:** add private persistent AI context ([#56](https://github.com/marcel-breuer/metricove/issues/56)) ([7a7ea18](https://github.com/marcel-breuer/metricove/commit/7a7ea1849dd7f9ad6e238b8733c18423edb37890))
* **ai:** add scoped MCP access ([16bd4c5](https://github.com/marcel-breuer/metricove/commit/16bd4c504a13c8d35f616ae8f5332ae1099d92f6))
* **ai:** add user-scoped persistent context storage ([#56](https://github.com/marcel-breuer/metricove/issues/56)) ([7ccda91](https://github.com/marcel-breuer/metricove/commit/7ccda915a52d542525f4a2f54d6259f48aa9db44))
* **ai:** wire persistent context into API and assistant ([#56](https://github.com/marcel-breuer/metricove/issues/56)) ([5ccd0df](https://github.com/marcel-breuer/metricove/commit/5ccd0dfed16456370994a6cf0d63b03863935416))
* **ai:** wire persistent context into API and assistant ([#56](https://github.com/marcel-breuer/metricove/issues/56)) ([06a7f86](https://github.com/marcel-breuer/metricove/commit/06a7f866b76caa7cb6685f3f24b92a972098d672))
* **analytics:** add private cross-module dashboards ([cc6bf75](https://github.com/marcel-breuer/metricove/commit/cc6bf75a7f9dfcbdab33bff841f3bd94ceb69b94))
* **analytics:** add private cross-module dashboards ([65987d2](https://github.com/marcel-breuer/metricove/commit/65987d21b192065881f8018afc29eb84117b33d3))
* bootstrap Laravel API and SvelteKit web app ([272d010](https://github.com/marcel-breuer/metricove/commit/272d010bdff06c3a75ce258c3a38c146bc46fd8c))
* bootstrap Laravel API and SvelteKit web app ([79b7eb9](https://github.com/marcel-breuer/metricove/commit/79b7eb9acbf1bfbb08d8c67dd8cdd0bbcd3d10a2))
* bootstrap Laravel API and SvelteKit web app ([#30](https://github.com/marcel-breuer/metricove/issues/30)) ([272d010](https://github.com/marcel-breuer/metricove/commit/272d010bdff06c3a75ce258c3a38c146bc46fd8c))
* **core:** add admin email invitations ([2da9204](https://github.com/marcel-breuer/metricove/commit/2da920407122957d260e062948ca5490f8c0fe42))
* **core:** add admin email invitations ([#7](https://github.com/marcel-breuer/metricove/issues/7)) ([83ef207](https://github.com/marcel-breuer/metricove/commit/83ef20771f6e193cd661b8017510d89c5b0fb2a6))
* **core:** add atomic first-run setup ([#5](https://github.com/marcel-breuer/metricove/issues/5)) ([edb31d8](https://github.com/marcel-breuer/metricove/commit/edb31d80ecbc74f13282ac2b0c419ac58f397347))
* **core:** add atomic first-run setup ([#5](https://github.com/marcel-breuer/metricove/issues/5)) ([a0761c3](https://github.com/marcel-breuer/metricove/commit/a0761c32379b69fdddd9bf5e4a9b4d712cb97258))
* **core:** add module registry and activation controls ([c07d23c](https://github.com/marcel-breuer/metricove/commit/c07d23c9c57b3e2a1c557415206180bfa785d2a4))
* **core:** add per-member module permissions and record privacy ([5dc9def](https://github.com/marcel-breuer/metricove/commit/5dc9def0ef80727bea9effaa20f17f915192c072)), closes [#10](https://github.com/marcel-breuer/metricove/issues/10)
* **core:** add session auth and member profiles ([533a8b4](https://github.com/marcel-breuer/metricove/commit/533a8b4c911f253581ff41c54a1320c26c4816bd))
* **core:** add session authentication, profiles and password recovery ([798f5bf](https://github.com/marcel-breuer/metricove/commit/798f5bf8211b078645955326783d4dc2ce62f15e))
* **core:** protect last active administrator ([#8](https://github.com/marcel-breuer/metricove/issues/8)) ([8336f24](https://github.com/marcel-breuer/metricove/commit/8336f24a50f6cefa0800362c33fd09c78bb0cbed))
* **finance:** add transactions and monthly budgets ([b434a70](https://github.com/marcel-breuer/metricove/commit/b434a7045e169a8eb3be32c669fd75d2c3bda77c))
* format measurements by display unit ([bc76d73](https://github.com/marcel-breuer/metricove/commit/bc76d73d95e3ad4a5a5ccf37264049fcdbeb6b37))
* **import:** add mapped CSV flows and local OCR ([d7ea9a5](https://github.com/marcel-breuer/metricove/commit/d7ea9a5ebaf1b3305c6c1f82fb06590f71d9671e))
* **import:** mapped CSV flows and local OCR ([9c2e8cf](https://github.com/marcel-breuer/metricove/commit/9c2e8cf4960145985e778a68668574d09fe4f58f))
* **mcp:** support non-expiring external tokens ([ec3505d](https://github.com/marcel-breuer/metricove/commit/ec3505d493d8980242a7dc5246dd2b0793210afe))
* **mcp:** support non-expiring external tokens ([83dff38](https://github.com/marcel-breuer/metricove/commit/83dff3835ef9229964f84b5ee75ac51db9427151)), closes [#71](https://github.com/marcel-breuer/metricove/issues/71)
* **meal-planning:** add editable weekly meal plans ([f4bf13e](https://github.com/marcel-breuer/metricove/commit/f4bf13e523167f7563db76abeb4707ecaef84f0b))
* **ops:** add backup/restore and privacy hardening ([b34364f](https://github.com/marcel-breuer/metricove/commit/b34364f063d551508053449376c046dd22c3de70))
* **ops:** add verified backup and restore workflow ([34971f1](https://github.com/marcel-breuer/metricove/commit/34971f16a88dd271f6ee6fb325808d0e6a0f4191))
* **pantry:** track household stock and best-before dates ([db53d33](https://github.com/marcel-breuer/metricove/commit/db53d33de1ebb10aacd755ec823b7b4e9af73153))
* **recipes:** manage private and household recipes ([9358e59](https://github.com/marcel-breuer/metricove/commit/9358e59fb36d6a15bf248250cc24a3a41817b295))
* **release:** automate semantic version releases ([#75](https://github.com/marcel-breuer/metricove/issues/75)) ([9328cd8](https://github.com/marcel-breuer/metricove/commit/9328cd82f6f08fce3fb4022e0b3bcdd917c1faed))
* **release:** automate SemVer releases ([c414311](https://github.com/marcel-breuer/metricove/commit/c41431160e18174ad3f92a6829e0181d9a3ee625))
* **release:** publish immutable multi-arch images ([c18df99](https://github.com/marcel-breuer/metricove/commit/c18df9902530b1f505c322a027c7d88175154b17))
* **release:** publish immutable multi-arch images ([#53](https://github.com/marcel-breuer/metricove/issues/53)) ([8895fbb](https://github.com/marcel-breuer/metricove/commit/8895fbb23b2ed76c4e98aab811edaab41c4b833d)), closes [#26](https://github.com/marcel-breuer/metricove/issues/26)
* **release:** validate conventional release metadata ([9fc48ae](https://github.com/marcel-breuer/metricove/commit/9fc48ae261294b258618d556dcdb8022ef27f739))
* **shopping:** add collaborative household shopping lists ([550a868](https://github.com/marcel-breuer/metricove/commit/550a8687ef4d9b7136087341a0e9923d0e5692fa))
* **shopping:** add opt-in meal plan suggestions ([#20](https://github.com/marcel-breuer/metricove/issues/20)) ([7ef05ef](https://github.com/marcel-breuer/metricove/commit/7ef05efbfd55f25ae5801dd37cbd2b4fc8b9bd84))
* **shopping:** add opt-in meal plan suggestions ([#20](https://github.com/marcel-breuer/metricove/issues/20)) ([10532ea](https://github.com/marcel-breuer/metricove/commit/10532eaafbcf9a156de35523885597fd37d61d7e))
* **tracking:** add custom trackers and measurement history ([5dec81b](https://github.com/marcel-breuer/metricove/commit/5dec81b45c67de8a2b43d811d589a7e9fc83a303))
* **tracking:** custom trackers and measurement history ([#39](https://github.com/marcel-breuer/metricove/issues/39)) ([cb85570](https://github.com/marcel-breuer/metricove/commit/cb85570defbf32bba0ce96f148b3e24cb2550c7e)), closes [#12](https://github.com/marcel-breuer/metricove/issues/12)
* **ui:** unify module grants, measurement formatting, and themes ([64ebbe5](https://github.com/marcel-breuer/metricove/commit/64ebbe5d6c146c8c1067aa4b250e0f59fc0b2296))
* **utilities:** add meter readings and usage trends ([220c58c](https://github.com/marcel-breuer/metricove/commit/220c58ce2d9061d6b30ea6de2b26e07272b118a1)), closes [#13](https://github.com/marcel-breuer/metricove/issues/13)
* **utilities:** add meter readings and usage trends ([0764c71](https://github.com/marcel-breuer/metricove/commit/0764c71c66580a397b5f838f08828c6f49c0d983))
* **vehicles:** add fleet and operating records ([e07b1c0](https://github.com/marcel-breuer/metricove/commit/e07b1c00bfd82dcdfa7c69da4327bf0b8662174c)), closes [#14](https://github.com/marcel-breuer/metricove/issues/14)
* **vehicles:** add fleet and operating records ([f9c75fe](https://github.com/marcel-breuer/metricove/commit/f9c75fef9a73dac1c51c31392e4807c0d0459b40))
* **web:** add permission-aware responsive app shell ([ad34b3d](https://github.com/marcel-breuer/metricove/commit/ad34b3df768cf0a3d79278309cd16fcabcb3fdda)), closes [#11](https://github.com/marcel-breuer/metricove/issues/11)
* **web:** align first-admin setup with sign-in ([72f5dca](https://github.com/marcel-breuer/metricove/commit/72f5dca7586dfdfa24804b4f070c1516c3cadec7))
* **web:** align first-admin setup with sign-in ([fb7a5de](https://github.com/marcel-breuer/metricove/commit/fb7a5de082dd5e777570c475beeb56c8b834669e))
* **web:** guide new admins to household features ([3aec22f](https://github.com/marcel-breuer/metricove/commit/3aec22ff266696065108fbdf87fc902ebd536741))
* **web:** guide new admins to household features ([f30b65e](https://github.com/marcel-breuer/metricove/commit/f30b65e645e31299d93ccf33792fd68627c206ea))
* **web:** improve login screen usability ([#64](https://github.com/marcel-breuer/metricove/issues/64)) ([05a92f4](https://github.com/marcel-breuer/metricove/commit/05a92f4f1de610e1585e80576d38460a3c81b242)), closes [#63](https://github.com/marcel-breuer/metricove/issues/63)
* **web:** polish login screen ([#63](https://github.com/marcel-breuer/metricove/issues/63)) ([b461e8f](https://github.com/marcel-breuer/metricove/commit/b461e8f5d82b461057886bd49fc0286678bdc612))


### Bug Fixes

* **ai:** keep saved context outside system prompt ([87faf79](https://github.com/marcel-breuer/metricove/commit/87faf799b29c20ae301f1a475ac08e309cce4fbf))
* **api:** preserve initial setup when seeding ([f182e1c](https://github.com/marcel-breuer/metricove/commit/f182e1c4d556c51bbabbbd9397230cb76e605937))
* **api:** preserve initial setup when seeding ([3a91396](https://github.com/marcel-breuer/metricove/commit/3a91396f3d0ecf3cb5f11982b08cc02eed8bfe3c))
* **ci:** pass compose timeout to down command ([a6e7706](https://github.com/marcel-breuer/metricove/commit/a6e770695a3ac285bd2d830b23c04eefd30f793e))
* **compose:** avoid pulling local API image in Coolify ([decf2e7](https://github.com/marcel-breuer/metricove/commit/decf2e7b53c5af07124d379e28e4295e0028662c))
* **compose:** mark runtime API image consumers buildable ([29499d5](https://github.com/marcel-breuer/metricove/commit/29499d564dc6e8036d478384f4b5aae0798854ac))
* **dependabot:** align paths and weekly review schedule ([cacb338](https://github.com/marcel-breuer/metricove/commit/cacb33812aa6ff6bfa3c45fb2bdff4b7794cb779))
* **dependabot:** move pull request limit to group ([875f824](https://github.com/marcel-breuer/metricove/commit/875f824de81cde21ff45bc9ff16e593893795ccb))
* **deps:** keep PostgreSQL 17 for volume compatibility ([3ee4c05](https://github.com/marcel-breuer/metricove/commit/3ee4c05120d16ed1adbb27cc0318632172f080d6))
* **deps:** retain TypeScript 6 for Svelte toolchain ([4b47169](https://github.com/marcel-breuer/metricove/commit/4b471690cc1da5537bf97159959b248ac7be9051))
* **docker:** pass AI context retention setting ([02973c7](https://github.com/marcel-breuer/metricove/commit/02973c7462568ab6f76d50ef495abd3260ab9618))
* ignore incompatible TypeScript major updates ([56569aa](https://github.com/marcel-breuer/metricove/commit/56569aac75e1666ff46c27224488a95a1f2e168e))
* **ops:** refresh gateway after recovery ([b5ca7c4](https://github.com/marcel-breuer/metricove/commit/b5ca7c467198902a71927b8cec490a408c4880db))
* patch critical PHP security vulnerabilities ([a56a64d](https://github.com/marcel-breuer/metricove/commit/a56a64d5689899ea71f6bee9aea3369c7d865f08))
* patch critical PHP security vulnerabilities ([36f7e7a](https://github.com/marcel-breuer/metricove/commit/36f7e7aabe8be502b023db8c13bebfa7f127cfd5))
* prevent incompatible TypeScript major updates ([0cd390c](https://github.com/marcel-breuer/metricove/commit/0cd390c0b0561897a417527a1e5188da2c81da96))
* **release:** use configured workflow token ([9dc1274](https://github.com/marcel-breuer/metricove/commit/9dc1274f72307cdb8283314e9ddc9e2c015bb8f1))
* **release:** use configured workflow token ([#83](https://github.com/marcel-breuer/metricove/issues/83)) ([0cae5c6](https://github.com/marcel-breuer/metricove/commit/0cae5c6b67769a2a98a1a1faaa846f47540acf85))
* restore Composer lockfile blob ([863325e](https://github.com/marcel-breuer/metricove/commit/863325e1af4430a922104046c9928aa36a7c8f1e))
* **test:** serialize Compose storage initialization ([ca4ba03](https://github.com/marcel-breuer/metricove/commit/ca4ba0392190e4c5b297719bea02cc9894f569ff))
* **tracking:** preserve local measurement timestamps ([63db709](https://github.com/marcel-breuer/metricove/commit/63db7092867bdccc7a02f6a1790ebf06edd7f7c2))
* **ui:** format displayed numbers by unit ([79ba09b](https://github.com/marcel-breuer/metricove/commit/79ba09b7b3d86e813725abca803103162fb795d3))
* **ui:** format numeric values consistently ([4469219](https://github.com/marcel-breuer/metricove/commit/4469219374723b7c200d52736f037a2e5224337e))
* use floating PHP 8.5 image tags ([907a019](https://github.com/marcel-breuer/metricove/commit/907a0191b2ea8717ef1f260a5bc18906766c15f9))
* use released PHP security image tags ([265c786](https://github.com/marcel-breuer/metricove/commit/265c786100635904d05d61ce1ea82b7a475aaf43))
* **web:** redirect signed-out home visits to sign-in ([1941dd9](https://github.com/marcel-breuer/metricove/commit/1941dd99242124fa8b134560d85e01d7d4d4260d))
* **web:** redirect signed-out home visits to sign-in ([e61f8c9](https://github.com/marcel-breuer/metricove/commit/e61f8c945ba2bdcb116ae3a13a5fac087f19923f))

## [Unreleased]
- Initialize repository documentation, license, modular architecture guidance and contribution policies.
