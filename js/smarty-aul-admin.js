jQuery(document).ready(function($) {
    // Handle tab switching
    $(".smarty-aul-nav-tab").click(function (e) {
        e.preventDefault();
        $(".smarty-aul-nav-tab").removeClass("smarty-aul-nav-tab-active");
        $(this).addClass("smarty-aul-nav-tab-active");

        $(".smarty-aul-tab-content").removeClass("active");
        $($(this).attr("href")).addClass("active");
    });

    // Load README.md
    $("#smarty-aul-load-readme-btn").click(function () {
        const $content = $("#smarty-aul-readme-content");
        $content.html("<p>Loading...</p>");

        $.ajax({
            url: smartyAutoInternalLinker.ajaxUrl,
            type: "POST",
            data: {
                action: "smarty_aul_load_readme",
                nonce: smartyAutoInternalLinker.nonce,
            },
            success: function (response) {
                console.log(response);
                if (response.success) {
                    $content.html(response.data);
                } else {
                    $content.html("<p>Error loading README.md</p>");
                }
            },
        });
    });

    // Load CHANGELOG.md
    $("#smarty-aul-load-changelog-btn").click(function () {
        const $content = $("#smarty-aul-changelog-content");
        $content.html("<p>Loading...</p>");

        $.ajax({
            url: smartyAutoInternalLinker.ajaxUrl,
            type: "POST",
            data: {
                action: "smarty_aul_load_changelog",
                nonce: smartyAutoInternalLinker.nonce,
            },
            success: function (response) {
                console.log(response);
                if (response.success) {
                    $content.html(response.data);
                } else {
                    $content.html("<p>Error loading CHANGELOG.md</p>");
                }
            },
        });
    });
});