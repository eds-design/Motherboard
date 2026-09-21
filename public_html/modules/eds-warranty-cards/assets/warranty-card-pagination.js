(function () {
    'use strict';

    var instances = [];
    var resizeTimer = null;

    function afterLayout() {
        return new Promise(function (resolve) {
            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(resolve);
            });
        });
    }

    function waitForAssets(root) {
        var pending = [];
        var images = root.querySelectorAll('img');

        images.forEach(function (image) {
            if (image.complete) {
                if (typeof image.decode === 'function') {
                    pending.push(image.decode().catch(function () {}));
                }
                return;
            }

            pending.push(new Promise(function (resolve) {
                image.addEventListener('load', resolve, { once: true });
                image.addEventListener('error', resolve, { once: true });
            }));
        });

        if (document.fonts && document.fonts.ready) {
            pending.push(document.fonts.ready.catch(function () {}));
        }

        return Promise.all(pending).then(afterLayout);
    }

    function WarrantyPaginator(container) {
        var source = container.querySelector('.eds-warranty-document-source');
        if (!source) {
            throw new Error('Warranty card source document is missing.');
        }

        this.container = container;
        this.source = source.cloneNode(true);
        this.modeClass = source.classList.contains('eds-warranty-document--print')
            ? 'eds-warranty-document--print'
            : 'eds-warranty-document--screen';
        this.pagesRoot = null;
        this.currentPage = null;
        this.currentContent = null;
    }

    WarrantyPaginator.prototype.createPage = function () {
        var shell = document.createElement('div');
        var page = document.createElement('article');
        var content = document.createElement('div');

        shell.className = 'eds-warranty-page-shell';
        page.className = 'eds-warranty-document eds-warranty-page ' + this.modeClass;
        content.className = 'eds-warranty-page__content';
        page.appendChild(content);
        shell.appendChild(page);
        this.pagesRoot.appendChild(shell);
        this.currentPage = page;
        this.currentContent = content;
        return content;
    };

    WarrantyPaginator.prototype.pageFits = function () {
        return this.currentContent.scrollHeight <= this.currentContent.clientHeight + 1;
    };

    WarrantyPaginator.prototype.pageIsEmpty = function () {
        return !this.currentContent || this.currentContent.childElementCount === 0;
    };

    WarrantyPaginator.prototype.appendAtomic = function (node) {
        this.currentContent.appendChild(node);
        if (this.pageFits()) {
            return;
        }

        this.currentContent.removeChild(node);
        if (!this.pageIsEmpty()) {
            this.createPage();
        }
        this.currentContent.appendChild(node);
        if (!this.pageFits()) {
            node.classList.add('eds-warranty-oversized-block');
        }
    };

    WarrantyPaginator.prototype.createTableSection = function (sourceSection) {
        var section = sourceSection.cloneNode(false);
        var sourceTable = sourceSection.querySelector('table');
        var table = sourceTable.cloneNode(false);
        var colgroup = sourceTable.querySelector('colgroup');
        var thead = sourceTable.querySelector('thead');
        var tbody = document.createElement('tbody');

        if (colgroup) {
            table.appendChild(colgroup.cloneNode(true));
        }
        if (thead) {
            table.appendChild(thead.cloneNode(true));
        }
        table.appendChild(tbody);
        section.appendChild(table);
        this.currentContent.appendChild(section);

        if (!this.pageFits() && !this.pageIsEmpty()) {
            this.currentContent.removeChild(section);
            this.createPage();
            this.currentContent.appendChild(section);
        }

        return { section: section, tbody: tbody };
    };

    WarrantyPaginator.prototype.appendTable = function (sourceSection) {
        var sourceRows = sourceSection.querySelectorAll('tbody > tr');
        var tablePart = this.createTableSection(sourceSection);
        var self = this;

        sourceRows.forEach(function (sourceRow) {
            var row = sourceRow.cloneNode(true);
            tablePart.tbody.appendChild(row);
            if (self.pageFits()) {
                return;
            }

            tablePart.tbody.removeChild(row);
            if (tablePart.tbody.children.length === 0) {
                self.currentContent.removeChild(tablePart.section);
            }
            self.createPage();
            tablePart = self.createTableSection(sourceSection);
            tablePart.tbody.appendChild(row);

            if (!self.pageFits()) {
                tablePart.tbody.removeChild(row);
                tablePart = self.appendOversizedTableRow(sourceRow, sourceSection, tablePart);
            }
        });
    };

    function stringBreakPoints(value, charactersOnly) {
        var points = [];
        if (charactersOnly) {
            var offset = 0;
            Array.from(value).forEach(function (character) {
                offset += character.length;
                points.push(offset);
            });
            return points;
        }

        var expression = /\s+/gu;
        var match;
        while ((match = expression.exec(value))) {
            points.push(match.index + match[0].length);
        }
        if (!points.length || points[points.length - 1] !== value.length) {
            points.push(value.length);
        }
        return points;
    }

    WarrantyPaginator.prototype.largestFittingCellEnd = function (cell, value, start, points) {
        var available = points.filter(function (point) { return point > start; });
        var low = 0;
        var high = available.length - 1;
        var best = null;

        while (low <= high) {
            var middle = Math.floor((low + high) / 2);
            var end = available[middle];
            cell.textContent = value.slice(start, end);
            if (this.pageFits()) {
                best = end;
                low = middle + 1;
            } else {
                high = middle - 1;
            }
        }
        cell.textContent = '';
        return best;
    };

    WarrantyPaginator.prototype.appendOversizedTableRow = function (sourceRow, sourceSection, tablePart) {
        var sourceCells = Array.from(sourceRow.children);
        var values = sourceCells.map(function (cell) { return cell.textContent || ''; });
        var offsets = values.map(function () { return 0; });
        var firstFragment = true;

        while (offsets.some(function (offset, index) { return offset < values[index].length; })) {
            var row = sourceRow.cloneNode(false);
            var cells = sourceCells.map(function (sourceCell) {
                var cell = sourceCell.cloneNode(false);
                row.appendChild(cell);
                return cell;
            });
            row.classList.add('eds-warranty-table-row--oversized');
            if (!firstFragment) {
                row.classList.add('eds-warranty-table-row--continued');
            }
            tablePart.tbody.appendChild(row);

            var madeProgress = false;
            values.forEach(function (value, index) {
                if (offsets[index] >= value.length) {
                    return;
                }
                var end = this.largestFittingCellEnd(cells[index], value, offsets[index], stringBreakPoints(value, false));
                if (end === null) {
                    end = this.largestFittingCellEnd(cells[index], value, offsets[index], stringBreakPoints(value, true));
                }
                if (end !== null && end > offsets[index]) {
                    cells[index].textContent = value.slice(offsets[index], end);
                    offsets[index] = end;
                    madeProgress = true;
                }
            }, this);

            if (!madeProgress) {
                row.classList.add('eds-warranty-forced-break');
                values.forEach(function (value, index) {
                    cells[index].textContent = value.slice(offsets[index]);
                    offsets[index] = value.length;
                });
                return tablePart;
            }

            firstFragment = false;
            if (offsets.some(function (offset, index) { return offset < values[index].length; })) {
                this.createPage();
                tablePart = this.createTableSection(sourceSection);
            }
        }
        return tablePart;
    };

    function textNodes(root) {
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        var nodes = [];
        var node;
        while ((node = walker.nextNode())) {
            nodes.push(node);
        }
        return nodes;
    }

    function textLength(root) {
        return textNodes(root).reduce(function (total, node) {
            return total + node.nodeValue.length;
        }, 0);
    }

    function rangePoint(root, offset) {
        var nodes = textNodes(root);
        var consumed = 0;
        var index;

        for (index = 0; index < nodes.length; index += 1) {
            if (offset <= consumed + nodes[index].nodeValue.length) {
                return [nodes[index], offset - consumed];
            }
            consumed += nodes[index].nodeValue.length;
        }

        if (nodes.length) {
            return [nodes[nodes.length - 1], nodes[nodes.length - 1].nodeValue.length];
        }
        return [root, 0];
    }

    function cloneTextRange(source, start, end) {
        var clone = source.cloneNode(false);
        var range = document.createRange();
        var startPoint = rangePoint(source, start);
        var endPoint = rangePoint(source, end);

        range.setStart(startPoint[0], startPoint[1]);
        range.setEnd(endPoint[0], endPoint[1]);
        clone.appendChild(range.cloneContents());
        return clone;
    }

    function splitPoints(source) {
        var text = source.textContent || '';
        var points = [];
        var expression = /\s+/gu;
        var match;

        while ((match = expression.exec(text))) {
            points.push(match.index + match[0].length);
        }
        if (!points.length || points[points.length - 1] !== text.length) {
            points.push(text.length);
        }
        return points;
    }

    function characterPoints(source) {
        var text = source.textContent || '';
        return stringBreakPoints(text, true);
    }

    WarrantyPaginator.prototype.largestFittingEnd = function (source, start, points, appendCandidate, removeCandidate) {
        var available = points.filter(function (point) { return point > start; });
        var low = 0;
        var high = available.length - 1;
        var best = null;

        while (low <= high) {
            var middle = Math.floor((low + high) / 2);
            var end = available[middle];
            var candidate = appendCandidate(cloneTextRange(source, start, end), start);
            var fits = this.pageFits();
            removeCandidate(candidate);
            if (fits) {
                best = end;
                low = middle + 1;
            } else {
                high = middle - 1;
            }
        }
        return best;
    };

    WarrantyPaginator.prototype.splitTextBlock = function (source, makeHost, appendCandidate, removeCandidate) {
        var total = textLength(source);
        var wordPoints = splitPoints(source);
        var forcedPoints = null;
        var start = 0;
        var lastHost = null;

        while (start < total) {
            var host = makeHost();
            var end = this.largestFittingEnd(source, start, wordPoints, appendCandidate.bind(null, host), removeCandidate);

            if (end === null) {
                var hostIsOnlyContent = this.currentContent.childElementCount === 1
                    && this.currentContent.firstElementChild === host.section;
                if (!hostIsOnlyContent) {
                    host.removeIfEmpty();
                    this.createPage();
                    continue;
                }
                forcedPoints = forcedPoints || characterPoints(source);
                end = this.largestFittingEnd(source, start, forcedPoints, appendCandidate.bind(null, host), removeCandidate);
            }

            if (end === null || end <= start) {
                var remainder = cloneTextRange(source, start, total);
                remainder.classList.add('eds-warranty-forced-break');
                appendCandidate(host, remainder, start);
                return host;
            }

            appendCandidate(host, cloneTextRange(source, start, end), start);
            lastHost = host;
            start = end;
            if (start < total) {
                this.createPage();
            }
        }
        return lastHost;
    };

    WarrantyPaginator.prototype.createTermsSection = function (sourceSection, includeTitle) {
        var section = sourceSection.cloneNode(false);
        var content = document.createElement('div');
        var title = sourceSection.querySelector('.eds-warranty-terms__title');

        section.removeAttribute('id');
        if (includeTitle && title) {
            section.id = sourceSection.id;
            section.appendChild(title.cloneNode(true));
        }
        content.className = 'eds-warranty-terms__content';
        section.appendChild(content);
        this.currentContent.appendChild(section);

        return {
            section: section,
            content: content,
            removeIfEmpty: function () {
                if (!content.childElementCount && section.parentNode) {
                    section.parentNode.removeChild(section);
                }
            }
        };
    };

    WarrantyPaginator.prototype.appendLongParagraph = function (sourceBlock, sourceSection, includeTitle) {
        var self = this;
        var titlePending = includeTitle;
        return this.splitTextBlock(
            sourceBlock,
            function () {
                var host = self.createTermsSection(sourceSection, titlePending);
                titlePending = false;
                return host;
            },
            function (host, candidate) {
                host.content.appendChild(candidate);
                return { host: host, candidate: candidate };
            },
            function (entry) {
                entry.host.content.removeChild(entry.candidate);
            }
        );
    };

    WarrantyPaginator.prototype.appendListByItems = function (sourceList, sourceSection, includeTitle) {
        var self = this;
        var ordered = sourceList.tagName.toLowerCase() === 'ol';
        var itemNumber = 1;
        var titlePending = includeTitle;
        var currentHost = null;
        var currentList = null;

        function createHostWithList() {
            var host = self.createTermsSection(sourceSection, titlePending);
            host.hadTitle = titlePending;
            titlePending = false;
            var list = sourceList.cloneNode(false);
            if (ordered) {
                list.setAttribute('start', String(itemNumber));
            }
            host.content.appendChild(list);
            host.list = list;
            return host;
        }

        Array.from(sourceList.children).forEach(function (sourceItem) {
            if (!currentHost || currentHost.section.parentNode !== self.currentContent) {
                currentHost = createHostWithList();
                currentList = currentHost.list;
            }
            var item = sourceItem.cloneNode(true);
            currentList.appendChild(item);

            if (self.pageFits()) {
                itemNumber += 1;
                return;
            }

            currentList.removeChild(item);
            if (!currentList.childElementCount) {
                currentHost.section.parentNode.removeChild(currentHost.section);
                if (currentHost.hadTitle) {
                    titlePending = true;
                }
            }
            if (!self.pageIsEmpty()) {
                self.createPage();
            }
            currentHost = createHostWithList();
            currentList = currentHost.list;
            item = sourceItem.cloneNode(true);
            currentList.appendChild(item);

            if (self.pageFits()) {
                itemNumber += 1;
                return;
            }

            currentList.removeChild(item);
            currentHost.section.parentNode.removeChild(currentHost.section);
            if (currentHost.hadTitle) {
                titlePending = true;
            }
            currentHost = self.splitTextBlock(
                sourceItem,
                function () {
                    return createHostWithList();
                },
                function (splitHost, candidate, start) {
                    if (start > 0) {
                        candidate.classList.add('eds-warranty-list-item--continued');
                    }
                    splitHost.list.appendChild(candidate);
                    return { host: splitHost, candidate: candidate };
                },
                function (entry) {
                    entry.host.list.removeChild(entry.candidate);
                }
            );
            currentList = currentHost ? currentHost.list : null;
            itemNumber += 1;
        });
        return currentHost;
    };

    WarrantyPaginator.prototype.appendTerms = function (sourceSection) {
        var sourceContent = sourceSection.querySelector('.eds-warranty-terms__content');
        var blocks = [];
        var inlineParagraph = null;
        var titlePending = true;
        var activeHost = null;
        var self = this;

        if (sourceContent) {
            Array.from(sourceContent.childNodes).forEach(function (node) {
                var isBlock = node.nodeType === Node.ELEMENT_NODE && node.matches('p, ul, ol');
                if (isBlock) {
                    if (inlineParagraph && inlineParagraph.textContent.trim() !== '') {
                        blocks.push(inlineParagraph);
                    }
                    inlineParagraph = null;
                    blocks.push(node);
                    return;
                }

                var hasVisibleText = node.nodeType === Node.TEXT_NODE
                    ? node.nodeValue.trim() !== ''
                    : node.nodeType === Node.ELEMENT_NODE;
                if (!hasVisibleText) {
                    return;
                }
                if (!inlineParagraph) {
                    inlineParagraph = document.createElement('p');
                }
                inlineParagraph.appendChild(node.cloneNode(true));
            });
            if (inlineParagraph && (inlineParagraph.textContent.trim() !== '' || inlineParagraph.querySelector('br'))) {
                blocks.push(inlineParagraph);
            }
        }

        function createHost() {
            var host = self.createTermsSection(sourceSection, titlePending);
            host.hadTitle = titlePending;
            titlePending = false;
            return host;
        }

        function removeEmptyHost(host) {
            if (host.content.childElementCount || !host.section.parentNode) {
                return;
            }
            host.section.parentNode.removeChild(host.section);
            if (host.hadTitle) {
                titlePending = true;
            }
        }

        blocks.forEach(function (sourceBlock) {
            if (!activeHost || activeHost.section.parentNode !== self.currentContent) {
                activeHost = createHost();
            }
            var block = sourceBlock.cloneNode(true);
            activeHost.content.appendChild(block);
            if (self.pageFits()) {
                return;
            }

            activeHost.content.removeChild(block);
            removeEmptyHost(activeHost);
            if (!self.pageIsEmpty()) {
                self.createPage();
            }
            activeHost = createHost();
            block = sourceBlock.cloneNode(true);
            activeHost.content.appendChild(block);
            if (self.pageFits()) {
                return;
            }

            activeHost.content.removeChild(block);
            removeEmptyHost(activeHost);
            if (sourceBlock.matches('ul, ol')) {
                activeHost = self.appendListByItems(sourceBlock, sourceSection, titlePending);
            } else {
                activeHost = self.appendLongParagraph(sourceBlock, sourceSection, titlePending);
            }
            titlePending = false;
        });
    };

    WarrantyPaginator.prototype.applyScale = function () {
        var stage = this.container.closest('.eds-warranty-document-stage');
        var firstPage = this.container.querySelector('.eds-warranty-page');
        if (!stage || !firstPage || window.matchMedia('print').matches) {
            this.container.style.removeProperty('--eds-warranty-page-scale');
            return;
        }

        var style = window.getComputedStyle(stage);
        var available = stage.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
        var scale = Math.min(1, available / firstPage.offsetWidth);
        this.container.style.setProperty('--eds-warranty-page-scale', String(Math.max(scale, 0.1)));
    };

    WarrantyPaginator.prototype.paginate = function () {
        var source = this.source.cloneNode(true);
        var children = Array.from(source.children);
        var pagesRoot = document.createElement('div');
        var self = this;

        pagesRoot.className = 'eds-warranty-pages';
        this.pagesRoot = pagesRoot;
        this.container.replaceChildren(pagesRoot);
        this.createPage();

        children.forEach(function (child) {
            if (child.classList.contains('eds-warranty-items')) {
                self.appendTable(child);
            } else if (child.classList.contains('eds-warranty-terms')) {
                self.appendTerms(child);
            } else {
                self.appendAtomic(child.cloneNode(true));
            }
        });

        Array.from(pagesRoot.children).forEach(function (shell) {
            if (!shell.querySelector('.eds-warranty-page__content').childElementCount) {
                shell.parentNode.removeChild(shell);
            }
        });

        if (!pagesRoot.children.length) {
            throw new Error('Warranty card pagination produced no pages.');
        }

        this.container.classList.add('eds-warranty-document-set--paginated');
        this.applyScale();
    };

    WarrantyPaginator.prototype.restore = function () {
        this.container.replaceChildren(this.source.cloneNode(true));
        this.container.classList.remove('eds-warranty-document-set--paginated');
        this.container.style.removeProperty('--eds-warranty-page-scale');
    };

    function paginateAll() {
        instances.forEach(function (instance) {
            try {
                instance.paginate();
            } catch (error) {
                instance.restore();
                if (window.console && typeof window.console.error === 'function') {
                    window.console.error('Warranty card pagination failed.', error);
                }
            }
        });
    }

    window.edsWarrantyPaginationReady = new Promise(function (resolve) {
        function start() {
            var containers = document.querySelectorAll('[data-eds-warranty-pagination]');
            try {
                containers.forEach(function (container) {
                    instances.push(new WarrantyPaginator(container));
                });
            } catch (error) {
                resolve();
                return;
            }

            waitForAssets(document).then(function () {
                paginateAll();
                resolve();
                window.dispatchEvent(new CustomEvent('eds-warranty-pagination-ready'));
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', start, { once: true });
        } else {
            start();
        }
    });

    window.addEventListener('resize', function () {
        if (window.matchMedia('print').matches) {
            return;
        }
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(function () {
            paginateAll();
        }, 160);
    });
}());
