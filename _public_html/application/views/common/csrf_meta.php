<?php if (config_item('csrf_protection') === TRUE): ?>
<meta name="csrf-token" content="<?php echo html_escape($this->security->get_csrf_hash()); ?>">
<meta name="csrf-param" content="<?php echo html_escape($this->security->get_csrf_token_name()); ?>">
<?php endif; ?>
