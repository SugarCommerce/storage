/**
 * S3 Browser — integration for a page that is not a post editor.
 *
 * The scripts beside this one each know one plugin's file table. This one
 * knows nothing about the page it is on: it puts the browser into whatever
 * media frame the page opens, and when a file is chosen it hands the file to
 * the page, which is the only thing that knows where it goes.
 *
 * The page says so by defining, before a frame is opened:
 *
 *     window.S3BrowserHost = {
 *         insert: function ( files ) {
 *             // files: [ { fileName, bucket, key } ]
 *         }
 *     };
 */
(function ($) {
    'use strict';

    window.S3BrowserHostIntegration = {
        frameInstance: null,
        mediaFrame: null,

        init: function () {
            if (typeof S3BrowserGlobalConfig === 'undefined') {
                console.error('S3BrowserGlobalConfig is not defined!');
                return;
            }

            this.listenForInserts();
            this.extendMediaFrame();
        },

        /**
         * Carry out an insert the browser asked for.
         *
         * By message rather than the browser writing here directly: it renders
         * in a frame, and this page, which holds the fields, is the only one
         * that should fill them.
         */
        listenForInserts: function () {
            var self = this;

            window.addEventListener('message', function (event) {
                var data = event.data;

                if (!data || data.type !== 's3-browser:insert') {
                    return;
                }

                // Origin will not identify the sender: a frame with an opaque
                // origin posts as "null". The token comes from this page's own
                // configuration, so a frame without it is not ours.
                if (!data.token || data.token !== S3BrowserGlobalConfig.insertToken) {
                    return;
                }

                var host = window.S3BrowserHost;

                // Asked for on the way in rather than at load: a page may
                // define its handler after this script has run.
                if (!host || typeof host.insert !== 'function') {
                    window.console.warn('S3 Browser: this page has not defined window.S3BrowserHost.insert.');
                    return;
                }

                try {
                    host.insert(data.files || []);
                } catch (e) {
                    // The page's own handler failed. Leave the frame open so
                    // the files are still there to try again with.
                    window.console.error('S3 Browser: the page could not insert the files.', e);
                    return;
                }

                self.closeFrame();
            });
        },

        /**
         * Close the frame the browser was opened in.
         */
        closeFrame: function () {
            try {
                if (this.mediaFrame) {
                    this.mediaFrame.close();
                } else if (wp.media.frame) {
                    wp.media.frame.close();
                }
            } catch (e) {
                // A frame that will not close is not a reason to lose the
                // files that were just written.
            }
        },

        /**
         * Add the browser as a tab in the frames this page opens.
         */
        extendMediaFrame: function () {
            if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
                return;
            }

            var self = this;
            var config = S3BrowserGlobalConfig;
            var providerId = config.providerId;
            var providerName = config.providerName || 'S3 Files';
            var originalSelect = wp.media.view.MediaFrame.Select;

            wp.media.view.MediaFrame.Select = originalSelect.extend({
                initialize: function () {
                    originalSelect.prototype.initialize.apply(this, arguments);

                    this.states.add([
                        new wp.media.controller.State({
                            id: 's3_' + providerId,
                            title: providerName,
                            priority: 60,
                            content: 's3-content',
                            menu: 'default'
                        })
                    ]);

                    this.on('content:render:s3-content', this.s3ContentRender, this);

                    // Whichever frame was opened last is the one a chosen file
                    // belongs to. A page with several pickers builds a frame
                    // for each, and closing the first one built would close
                    // the wrong one.
                    this.on('open', function () {
                        self.mediaFrame = this;
                    }, this);
                },

                s3ContentRender: function () {
                    // One browser for the page, handed to whichever frame is
                    // showing. A page with a file field in every row builds a
                    // frame per click, and a browser per frame would be a
                    // bucket listing fetched again for each.
                    if (self.frameInstance) {
                        this.content.set(self.frameInstance);
                        return;
                    }

                    var params = {
                        chromeless: 1,
                        tab: 's3_' + providerId,

                        // Says the frame was opened by a host page. A browser
                        // confined to certain post types would otherwise find
                        // no post here to check and refuse to appear.
                        s3_host: 1
                    };

                    var bucketToUse = config.favoriteBucket || config.defaultBucket;

                    if (bucketToUse) {
                        params.bucket = bucketToUse;
                        params.view = 'objects';
                    } else {
                        params.view = 'buckets';
                    }

                    var $iframe = $('<iframe>', {
                        src: config.baseUrl + '?' + $.param(params),
                        class: 's3-browser-frame',
                        frameborder: 0,
                        style: 'width: 100%; height: 100%; border: none; min-height: 500px;'
                    });

                    var view = new wp.media.View({
                        controller: this,
                        className: 's3-browser-frame-wrapper',
                        tagName: 'div'
                    });

                    view.render = function () {
                        this.$el.html($iframe);
                        this.$el.css({
                            height: '100%',
                            minHeight: '500px',
                            width: '100%',
                            overflow: 'hidden'
                        });
                        return this;
                    };

                    this.content.set(view);
                    self.frameInstance = view;
                }
            });
        }
    };

    $(document).ready(function () {
        S3BrowserHostIntegration.init();
    });

})(jQuery);
