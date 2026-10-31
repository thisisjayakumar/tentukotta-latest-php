<?php if (config_item('csrf_protection') === TRUE): ?>
<script type="text/javascript">
(function () {
    var csrfName = <?php echo json_encode($this->security->get_csrf_token_name()); ?>;
    var csrfHash = <?php echo json_encode($this->security->get_csrf_hash()); ?>;

    window.CSRF = { name: csrfName, hash: csrfHash };

    if (!window.jQuery) {
        return;
    }

    // Attach the token to every state-changing request. A prefilter is used
    // instead of $.ajaxSetup({data: ...}) because many call sites pass their
    // data as an already-serialised string, which would replace the default.
    jQuery.ajaxPrefilter(function (options) {
        var method = (options.type || 'GET').toUpperCase();
        if (method === 'GET' || method === 'HEAD' || method === 'OPTIONS') {
            return;
        }

        var data = options.data;

        if (typeof data === 'string') {
            options.data = (data ? data + '&' : '')
                + encodeURIComponent(csrfName) + '=' + encodeURIComponent(csrfHash);
        } else if (window.FormData && data instanceof FormData) {
            data.append(csrfName, csrfHash);
        } else if (jQuery.isArray(data)) {
            data.push({ name: csrfName, value: csrfHash });
        } else if (jQuery.isPlainObject(data)) {
            data[csrfName] = csrfHash;
        } else if (data === undefined || data === null) {
            options.data = {};
            options.data[csrfName] = csrfHash;
        }
    });
})();
</script>
<?php endif; ?>
