-- Kronixon Connect: drop the module tables. The embedded blog tables (kx_blog_*, Phase 2) will be kept
-- unless the administrator asks to delete them (Spec §9 Uninstall).
DROP TABLE IF EXISTS `PREFIX_kx_nonce`;
DROP TABLE IF EXISTS `PREFIX_kx_idempotency`;
DROP TABLE IF EXISTS `PREFIX_kx_log`;
DROP TABLE IF EXISTS `PREFIX_kx_entity_map`;
DROP TABLE IF EXISTS `PREFIX_kx_image_map`;
DROP TABLE IF EXISTS `PREFIX_kx_redirect`;
DROP TABLE IF EXISTS `PREFIX_kx_outbox`;
