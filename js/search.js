
document.addEventListener('DOMContentLoaded', function () {

    // Main elements
    const searchBtn = document.getElementById('search-btn');
    const clearBtn = document.getElementById('clear-btn');
    const fileList = document.getElementById('fileList');
    const emptyContent = document.getElementById('emptycontent');
    const loading = document.getElementById('loading');
    const resultCount = document.getElementById('result-count');
    const viewListBtn = document.getElementById('view-list');
    const viewGridBtn = document.getElementById('view-grid');
    const fileTable = document.getElementById('filestable');
    const filenameInput = document.getElementById('filename');

    // Pagination elements
    const pagination = document.getElementById('pagination');
    const paginationInfo = document.getElementById('pagination-info');
    const firstPageBtn = document.getElementById('first-page');
    const prevPageBtn = document.getElementById('prev-page');
    const nextPageBtn = document.getElementById('next-page');
    const lastPageBtn = document.getElementById('last-page');
    const pageNumbers = document.getElementById('page-numbers');
    const pageSizeSelect = document.getElementById('page-size');

    // Check essential elements
    if (!searchBtn || !fileList || !emptyContent) {
        console.error('Essential elements not found!');
        return;
    }

    let currentView = 'grid';
    let currentPage = 1;
    let totalResults = 0;
    let pageSize = 25;
    let lastSearchParams = null;
    let fullTextSearchAvailable = true;
    let lastSearchType = 'traditional';
    let searchTimeout = null;

    // Event listeners
    if (searchBtn) searchBtn.addEventListener('click', () => performSearch(1));
    if (clearBtn) clearBtn.addEventListener('click', clearSearch);

    if (viewListBtn) viewListBtn.addEventListener('click', () => setView('list'));
    if (viewGridBtn) viewGridBtn.addEventListener('click', () => setView('grid'));

    if (firstPageBtn) firstPageBtn.addEventListener('click', () => goToPage(1));
    if (prevPageBtn) prevPageBtn.addEventListener('click', () => goToPage(currentPage - 1));
    if (nextPageBtn) nextPageBtn.addEventListener('click', () => goToPage(currentPage + 1));
    if (lastPageBtn) lastPageBtn.addEventListener('click', () => goToPage(Math.ceil(totalResults / pageSize)));

    if (pageSizeSelect) {
        pageSizeSelect.addEventListener('change', (e) => {
            pageSize = parseInt(e.target.value);
            currentPage = 1;
            if (lastSearchParams) {
                performSearch(1);
            }
        });
    }

    // Debounced search on input
    if (filenameInput) {
        filenameInput.addEventListener('input', function () {
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }
            searchTimeout = setTimeout(() => {
                performSearch(1);
            }, 500); // 500ms debounce
        });
    }

    // Search on Enter
    document.addEventListener('keypress', function (e) {
        if (e.key === 'Enter') {
            if (searchTimeout) clearTimeout(searchTimeout);
            performSearch(1);
        }
    });

    function performSearch(page = 1) {
        const query = document.getElementById('filename').value; // Using filename input as the main query input
        const fileType = document.getElementById('file-type').value;

        // Basic validation
        if (!query && !fileType) {
            // Don't show error if it's an automatic search from empty input, just clear
            if (page === 1 && !lastSearchParams) {
                 return;
            }
            // If user explicitly clicked search or it was a valid search before
            if (lastSearchParams) {
                clearSearch();
            }
            return;
        }

        lastSearchParams = {
            query: query,
            fileType: fileType,
            useFullTextSearch: true
        };

        currentPage = page;
        const offset = (page - 1) * pageSize;

        // Mostrar loading
        showLoading();

        fetch(OC.generateUrl('/apps/advancedsearch/api/search'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken
            },
            body: JSON.stringify({
                query: query,
                fileType: fileType,
                limit: pageSize,
                offset: offset,
                useFullTextSearch: true
            })
        })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(err => {
                        throw new Error(err.message || `HTTP error! status: ${response.status}`);
                    });
                }
                return response.json();
            })
            .then(data => {
                hideLoading();
                if (data.success) {
                    // Salvar tipo de busca usado e disponibilidade
                    lastSearchType = data.searchType || 'traditional';
                    fullTextSearchAvailable = data.fullTextSearchAvailable || false;

                    // console.log('Tipo de data.files:', typeof data.files);
                    // console.log('data.files é um array?', Array.isArray(data.files));
                    // console.log('Conteúdo de data.files:', data.files);
                    // console.log('Tipo de busca usado:', lastSearchType);
                    // console.log('Full text search disponível:', fullTextSearchAvailable);
                    // console.log('=== DEBUG INFO ===', data.debug);

                    // Para obter o total real, fazer uma busca sem limite
                    getTotalCount(lastSearchParams).then(total => {
                        totalResults = total;
                        displayResults(data.files || [], offset);
                        updatePagination();
                        updateSearchInfo(data);
                    });
                } else {
                    showError(data.message || 'Unknown error during search');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Search error:', error);
                showError(error.message || 'Connection error. Please try again.');
            });
    }

    function updateSearchInfo(data) {
        // Atualizar informações sobre o tipo de busca
        if (resultCount) {
            let searchInfo = '';
            if (data.searchType === 'fulltext') {
                searchInfo = ' (Search)';
            } else if (data.searchType === 'traditional') {
                searchInfo = ' (Traditional Search)';
            }

            const currentText = resultCount.textContent;
            if (currentText && !currentText.includes('Search)')) {
                resultCount.textContent = currentText + searchInfo;
            }
        }
    }

    function getTotalCount(params) {
        return fetch(OC.generateUrl('/apps/advancedsearch/api/search'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken
            },
            body: JSON.stringify({
                ...params,
                limit: 9999,
                offset: 0
            })
        })
            .then(response => response.json())
            .then(data => data.success ? data.count : 0)
            .catch(() => 0);
    }

    function goToPage(page) {
        if (page < 1 || page > Math.ceil(totalResults / pageSize)) {
            return;
        }
        performSearch(page);
    }

    function updatePagination() {
        if (!pagination) return;

        const totalPages = Math.ceil(totalResults / pageSize);

        // Mostrar/ocultar paginação
        if (totalResults > 0) {
            pagination.classList.remove('hidden');
        } else {
            pagination.classList.add('hidden');
            return;
        }

        // Atualizar informação
        const start = (currentPage - 1) * pageSize + 1;
        const end = Math.min(currentPage * pageSize, totalResults);
        if (paginationInfo) {
            paginationInfo.textContent = `Showing ${start}-${end} of ${totalResults} results`;
        }

        // Habilitar/desabilitar botões
        if (firstPageBtn) firstPageBtn.disabled = currentPage === 1;
        if (prevPageBtn) prevPageBtn.disabled = currentPage === 1;
        if (nextPageBtn) nextPageBtn.disabled = currentPage === totalPages;
        if (lastPageBtn) lastPageBtn.disabled = currentPage === totalPages;

        // Gerar números de página
        if (pageNumbers) {
            pageNumbers.innerHTML = '';

            // Se só tem uma página, não mostrar números
            if (totalPages === 1) {
                return;
            }

            // Lógica para mostrar páginas com elipses
            let startPage = Math.max(1, currentPage - 2);
            let endPage = Math.min(totalPages, currentPage + 2);

            if (currentPage <= 3) {
                endPage = Math.min(5, totalPages);
            }
            if (currentPage >= totalPages - 2) {
                startPage = Math.max(1, totalPages - 4);
            }

            // Primeira página
            if (startPage > 1) {
                addPageButton(1);
                if (startPage > 2) {
                    addEllipsis();
                }
            }

            // Páginas do meio
            for (let i = startPage; i <= endPage; i++) {
                addPageButton(i);
            }

            // Última página
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    addEllipsis();
                }
                addPageButton(totalPages);
            }
        }
    }

    function addPageButton(pageNum) {
        const button = document.createElement('button');
        button.className = 'pagination-button';
        button.textContent = pageNum;

        if (pageNum === currentPage) {
            button.classList.add('active');
        }

        button.addEventListener('click', () => goToPage(pageNum));
        if (pageNumbers) pageNumbers.appendChild(button);
    }

    function addEllipsis() {
        const span = document.createElement('span');
        span.className = 'pagination-ellipsis';
        span.textContent = '...';
        span.style.padding = '6px';
        span.style.color = 'var(--color-text-light)';
        if (pageNumbers) pageNumbers.appendChild(span);
    }

    function handleFileClick(event) {
        // Encontrar a linha clicada
        const row = event.target.closest('.file-row');
        if (!row) return;

        const fileId = row.getAttribute('data-file-id');
        const filePath = row.getAttribute('data-file-path');
        const fileName = row.getAttribute('data-file-name');
        const mimeType = row.getAttribute('data-mime-type');

        openFile(fileId, filePath, fileName, mimeType);
    }

    function clearSearch() {
        // Remover o event listener antes de limpar
        if (fileList) {
            fileList.removeEventListener('click', handleFileClick);
        }

        document.getElementById('filename').value = '';
        document.getElementById('file-type').value = '';

        fileList.innerHTML = '';
        if (resultCount) resultCount.textContent = '';

        // Voltar ao estado inicial
        showEmptyContent();

        // Restaurar texto inicial
        const emptyTitle = document.querySelector('#emptycontent h2');
        const emptyText = document.querySelector('#emptycontent p');
        if (emptyTitle) emptyTitle.textContent = 'Search Files';
        if (emptyText) emptyText.textContent = 'Use the search bar to find your files';

        lastSearchParams = null;
        currentPage = 1;
        totalResults = 0;
        lastSearchType = 'traditional';
    }

    function displayResults(files, offset) {
        if (!files || files.length === 0 && currentPage === 1) {
            showEmptyContent();
            const emptyTitle = document.querySelector('#emptycontent h2');
            const emptyText = document.querySelector('#emptycontent p');
            if (emptyTitle) emptyTitle.textContent = 'No results found';
            if (emptyText) emptyText.textContent = 'Try adjusting your search criteria';
            if (resultCount) resultCount.textContent = 'No results found';
            return;
        }

        hideEmptyContent();

        if (resultCount) {
            resultCount.textContent = `${totalResults} file${totalResults !== 1 ? 's' : ''} found`;
        }

        // Verificar se files é um array, caso contrário, converter
        const filesArray = Array.isArray(files) ? files : (
            typeof files === 'object' && files !== null ? Object.values(files) : []
        );

        // Limpar a área de resultados
        fileList.innerHTML = '';

        // Verificar qual visualização usar
        if (currentView === 'list') {
            displayListView(filesArray);
        } else {
            displayGridView(filesArray);
        }
    }

    function displayListView(files) {
        let html = '';

        files.forEach((file) => {
            const tags = file.tags.map(tag => `<span class="tag">${tag.name}</span>`).join(' ');
            const fileSize = formatFileSize(file.size);
            const fileDate = new Date(file.mtime * 1000).toLocaleDateString();
            const fileIcon = getFileIcon(file.name);

            // Adicionar indicador de busca avançada se disponível
            let searchIndicator = '';
            if (file.searchType === 'fulltext' && file.score) {
                searchIndicator = `<span class="search-score" title="Relevance: ${file.score.toFixed(2)}">⭐</span>`;
            }

            html += `
            <tr class="file-row">
                <td class="filename">
                    <a href="${OC.generateUrl('/apps/files/?fileid=' + file.id)}" 
                       style="text-decoration: none; color: inherit; display: block;">
                        <div style="display: flex; align-items: center;">
                            <div class="file-icon ${fileIcon}"></div>
                            <div>
                                <div class="file-name">${escapeHtml(file.name)} ${searchIndicator}</div>
                                <div class="file-path">${escapeHtml(file.path)}</div>
                                ${file.excerpt ? `<div class="file-excerpt" style="font-size: 12px; color: var(--color-text-lighter); margin-top: 4px;">${escapeHtml(file.excerpt)}</div>` : ''}
                            </div>
                        </div>
                    </a>
                </td>
                <td class="filesize">
                    <a href="${OC.generateUrl('/apps/files/?fileid=' + file.id)}" 
                       style="text-decoration: none; color: inherit; display: block;">
                        <span class="file-size">${fileSize}</span>
                    </a>
                </td>
                <td class="date">
                    <a href="${OC.generateUrl('/apps/files/?fileid=' + file.id)}" 
                       style="text-decoration: none; color: inherit; display: block;">
                        <span class="file-date">${fileDate}</span>
                    </a>
                </td>
                <td class="tags">
                    <div class="file-tags">${tags || '<span style="color: var(--color-text-light);">None</span>'}</div>
                </td>
            </tr>
        `;
        });

        fileList.innerHTML = html;

        if (fileTable) {
            fileTable.classList.add('list-view');
            fileTable.classList.remove('grid-view');
        }
    }

    async function displayGridView(files) {
        const gridContainer = document.createElement('div');
        gridContainer.className = 'grid-container';
        gridContainer.style.cssText = `
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 16px;
        padding: 16px;
        width: 100%;
    `;

        for (const file of files) {
            const isImage = file.mimetype?.startsWith('image/');
            const isVideo = file.mimetype?.startsWith('video/');
            const hasThumbnail = isImage || isVideo;

            const fileCard = document.createElement('div');
            fileCard.className = 'file-card';
            fileCard.style.cssText = `
            background: var(--color-background-hover);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            height: 100%;
            position: relative;
        `;

            // Indicador de relevância
            if (file.searchType === 'fulltext' && file.score) {
                const scoreIndicator = document.createElement('div');
                scoreIndicator.className = 'score-indicator';
                scoreIndicator.innerHTML = '⭐';
                scoreIndicator.title = `Relevance: ${file.score.toFixed(2)}`;
                scoreIndicator.style.cssText = `
                position: absolute;
                top: 8px;
                right: 8px;
                background: rgba(0,0,0,0.7);
                color: white;
                padding: 4px;
                border-radius: 4px;
                font-size: 12px;
                z-index: 1;
            `;
                fileCard.appendChild(scoreIndicator);
            }

            fileCard.addEventListener('mouseover', () => {
                fileCard.style.transform = 'translateY(-2px)';
                fileCard.style.boxShadow = '0 4px 6px rgba(0,0,0,0.1)';
            });

            fileCard.addEventListener('mouseout', () => {
                fileCard.style.transform = 'translateY(0)';
                fileCard.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
            });

            fileCard.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();

                if (isImage || isVideo) {
                    try {
                        // Use OCA.Viewer correctly
                        if (OCA.Viewer) {
                            OCA.Viewer.open({
                                fileInfo: {
                                    id: file.id,
                                    name: file.name,
                                    path: file.path,
                                    mime: file.mimetype,
                                    size: file.size,
                                    mtime: file.mtime
                                },
                                context: 'files'
                            });
                        } else {
                             console.error('OCA.Viewer not available');
                        }
                    } catch (err) {
                        console.error('Error opening viewer:', err);
                    }
                } else {
                    const fileUrl = OC.generateUrl('/apps/files/?fileid=' + file.id);
                    window.open(fileUrl, '_blank');
                }
            });

            const thumbnailArea = document.createElement('div');
            thumbnailArea.className = 'thumbnail-area';
            thumbnailArea.style.cssText = `
            height: 150px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--color-background-dark);
            position: relative;
        `;

            if (hasThumbnail) {
                const thumbnailUrl = OC.generateUrl('/core/preview?fileId=' + file.id + '&x=250&y=250&a=true');
                thumbnailArea.style.backgroundImage = `url('${thumbnailUrl}')`;
                thumbnailArea.style.backgroundSize = 'cover';
                thumbnailArea.style.backgroundPosition = 'center';
            } else {
                const fileIcon = document.createElement('div');
                fileIcon.className = `file-icon ${getFileIcon(file.name)}`;
                fileIcon.style.fontSize = '48px';
                thumbnailArea.appendChild(fileIcon);
            }

            const infoArea = document.createElement('div');
            infoArea.className = 'info-area';
            infoArea.style.cssText = `
            padding: 12px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        `;

            const fileName = document.createElement('div');
            fileName.className = 'file-name';
            fileName.textContent = file.name;
            fileName.style.cssText = `
            font-weight: bold;
            margin-bottom: 8px;
            word-break: break-word;
            white-space: normal;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        `;

            const fileDate = document.createElement('div');
            fileDate.className = 'file-date';
            fileDate.textContent = new Date(file.mtime * 1000).toLocaleDateString();
            fileDate.style.cssText = `
            font-size: 12px;
            color: var(--color-text-lighter);
            margin-bottom: 8px;
        `;

            const fileTags = document.createElement('div');
            fileTags.className = 'file-tags';
            fileTags.style.cssText = `
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: auto;
        `;

            if (file.tags && file.tags.length > 0) {
                file.tags.forEach(tag => {
                    const tagLink = document.createElement('a');
                    tagLink.href = OC.generateUrl('/apps/files/tags/' + tag.id + '?dir=/' + tag.id);
                    tagLink.style.textDecoration = 'none';
                    tagLink.target = '_blank';
                    tagLink.addEventListener('click', (event) => event.stopPropagation());

                    const tagElement = document.createElement('span');
                    tagElement.className = 'tag';
                    tagElement.textContent = tag.name;
                    tagElement.style.cssText = `
                    background: green;
                    color: white;
                    padding: 2px 6px;
                    border-radius: 4px;
                    font-size: 11px;
                    margin-right: 4px;
                `;

                    tagLink.appendChild(tagElement);
                    fileTags.appendChild(tagLink);
                });
            } else {
                const noTags = document.createElement('span');
                noTags.textContent = 'No tags';
                noTags.style.cssText = `
                font-size: 11px;
                color: var(--color-text-lighter);
            `;
                fileTags.appendChild(noTags);
            }

            infoArea.appendChild(fileName);
            infoArea.appendChild(fileDate);
            infoArea.appendChild(fileTags);

            fileCard.appendChild(thumbnailArea);
            fileCard.appendChild(infoArea);

            gridContainer.appendChild(fileCard);
        }

        fileList.innerHTML = '';
        fileList.appendChild(gridContainer);

        if (fileTable) {
            fileTable.classList.remove('list-view');
            fileTable.classList.add('grid-view');
        }
    }


    function setView(view) {
        if (view === currentView) return; // Não fazer nada se a visualização não mudou

        currentView = view;

        if (view === 'list' && viewListBtn && viewGridBtn) {
            viewListBtn.classList.add('active');
            viewGridBtn.classList.remove('active');
        } else if (view === 'grid' && viewListBtn && viewGridBtn) {
            viewGridBtn.classList.add('active');
            viewListBtn.classList.remove('active');
        }

        // Se houver resultados sendo exibidos, redesenhar com a nova visualização
        if (lastSearchParams && totalResults > 0) {
            // Obter os arquivos novamente com os mesmos parâmetros
            const offset = (currentPage - 1) * pageSize;

            fetch(OC.generateUrl('/apps/advancedsearch/api/search'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    ...lastSearchParams,
                    limit: pageSize,
                    offset: offset
                })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayResults(data.files, offset);
                    }
                })
                .catch(error => {
                    console.error('Error reloading results:', error);
                });
        }
    }

    function getFileIcon(filename) {
        const ext = filename.split('.').pop().toLowerCase();

        const iconMap = {
            'pdf': 'icon-filetype-pdf',
            'doc': 'icon-filetype-document',
            'docx': 'icon-filetype-document',
            'xls': 'icon-filetype-spreadsheet',
            'xlsx': 'icon-filetype-spreadsheet',
            'ppt': 'icon-filetype-presentation',
            'pptx': 'icon-filetype-presentation',
            'txt': 'icon-filetype-text',
            'jpg': 'icon-filetype-image',
            'jpeg': 'icon-filetype-image',
            'png': 'icon-filetype-image',
            'gif': 'icon-filetype-image',
            'mp4': 'icon-filetype-video',
            'avi': 'icon-filetype-video',
            'mp3': 'icon-filetype-audio',
            'wav': 'icon-filetype-audio',
            'zip': 'icon-filetype-archive',
            'rar': 'icon-filetype-archive'
        };

        return iconMap[ext] || 'icon-filetype-file';
    }

    function showLoading() {
        if (loading) loading.classList.remove('hidden');
        if (emptyContent) emptyContent.classList.add('hidden');
        if (fileTable) fileTable.classList.add('hidden');
        fileList.innerHTML = '';
        if (pagination) pagination.classList.add('hidden');
    }

    function hideLoading() {
        if (loading) loading.classList.add('hidden');
    }

    function showEmptyContent() {
        if (emptyContent) emptyContent.classList.remove('hidden');
        if (fileTable) fileTable.classList.add('hidden');
        fileList.innerHTML = '';
        if (pagination) pagination.classList.add('hidden');
    }

    function hideEmptyContent() {
        if (emptyContent) emptyContent.classList.add('hidden');
        if (fileTable) fileTable.classList.remove('hidden');
    }

    function showError(message) {
        showEmptyContent();
        document.querySelector('#emptycontent h2').textContent = 'Error';
        document.querySelector('#emptycontent p').textContent = message;
    }

    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialization
    showEmptyContent();
    document.querySelector('#emptycontent h2').textContent = 'Search Files';
    document.querySelector('#emptycontent p').textContent = 'Use the search bar to find your files';
});

