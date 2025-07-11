document.addEventListener('DOMContentLoaded', function () {
    // Elementos principais
    const searchBtn = document.getElementById('search-btn');
    const clearBtn = document.getElementById('clear-btn');
    const fileList = document.getElementById('fileList');
    const emptyContent = document.getElementById('emptycontent');
    const loading = document.getElementById('loading');
    const resultCount = document.getElementById('result-count');
    const viewListBtn = document.getElementById('view-list');
    const viewGridBtn = document.getElementById('view-grid');
    const fileTable = document.getElementById('filestable');

    // Elementos de paginação
    const pagination = document.getElementById('pagination');
    const paginationInfo = document.getElementById('pagination-info');
    const firstPageBtn = document.getElementById('first-page');
    const prevPageBtn = document.getElementById('prev-page');
    const nextPageBtn = document.getElementById('next-page');
    const lastPageBtn = document.getElementById('last-page');
    const pageNumbers = document.getElementById('page-numbers');
    const pageSizeSelect = document.getElementById('page-size');

    // Verificar elementos essenciais
    if (!searchBtn || !fileList || !emptyContent) {
        console.error('Elementos essenciais não encontrados!');
        return;
    }

    let currentView = 'list';
    let currentPage = 1;
    let totalResults = 0;
    let pageSize = 25;
    let lastSearchParams = null;

    // Event listeners - apenas adicionar se o elemento existir
    if (searchBtn) searchBtn.addEventListener('click', () => performSearch(1));
    if (clearBtn) clearBtn.addEventListener('click', clearSearch);

    // View buttons podem não existir em todas as páginas
    if (viewListBtn) viewListBtn.addEventListener('click', () => setView('list'));
    if (viewGridBtn) viewGridBtn.addEventListener('click', () => setView('grid'));

    // Event listeners de paginação
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

    // Busca ao pressionar Enter
    document.addEventListener('keypress', function (e) {
        if (e.key === 'Enter' && !e.target.matches('#tags')) {
            performSearch(1);
        }
    });

    function performSearch(page = 1) {
        const filename = document.getElementById('filename').value;
        const tagsInput = document.getElementById('tags').value;
        const tagOperator = document.querySelector('input[name="tagOperator"]:checked').value;
        const fileType = document.getElementById('file-type').value;

        const tags = tagsInput ? tagsInput.split(',').map(tag => tag.trim()).filter(tag => tag) : [];

        // Validação básica
        if (!filename && tags.length === 0 && !fileType) {
            showError('Por favor, insira pelo menos um critério de busca');
            return;
        }

        // Salvar parâmetros da última busca
        lastSearchParams = {
            filename: filename,
            tags: tags,
            tagOperator: tagOperator,
            fileType: fileType
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
                filename: filename,
                tags: tags,
                tagOperator: tagOperator,
                fileType: fileType,
                limit: pageSize,
                offset: offset
            })
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                hideLoading();
                if (data.success) {
                    // Para obter o total real, fazer uma busca sem limite
                    getTotalCount(lastSearchParams).then(total => {
                        totalResults = total;
                        displayResults(data.files, offset);
                        updatePagination();
                    });
                } else {
                    showError(data.message || 'Erro desconhecido na busca');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Erro na busca:', error);
                showError('Erro de conexão. Tente novamente.');
            });
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
            paginationInfo.textContent = `Mostrando ${start}-${end} de ${totalResults} resultados`;
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

    function clearSearch() {
        // Remover o event listener antes de limpar
        if (fileList) {
            fileList.removeEventListener('click', handleFileClick);
        }

        document.getElementById('filename').value = '';
        document.getElementById('tags').value = '';
        document.getElementById('file-type').value = '';
        const tagAndRadio = document.getElementById('tag-and');
        if (tagAndRadio) tagAndRadio.checked = true;

        fileList.innerHTML = '';
        if (resultCount) resultCount.textContent = '';

        // Voltar ao estado inicial
        showEmptyContent();

        // Restaurar texto inicial
        const emptyTitle = document.querySelector('#emptycontent h2');
        const emptyText = document.querySelector('#emptycontent p');
        if (emptyTitle) emptyTitle.textContent = 'Faça uma busca';
        if (emptyText) emptyText.textContent = 'Use os filtros ao lado para buscar seus arquivos';

        lastSearchParams = null;
        currentPage = 1;
        totalResults = 0;
    }

    function displayResults(files, offset) {
        if (files.length === 0 && currentPage === 1) {
            showEmptyContent();
            const emptyTitle = document.querySelector('#emptycontent h2');
            const emptyText = document.querySelector('#emptycontent p');
            if (emptyTitle) emptyTitle.textContent = 'Nenhum resultado encontrado';
            if (emptyText) emptyText.textContent = 'Tente ajustar seus critérios de busca';
            if (resultCount) resultCount.textContent = 'Nenhum resultado encontrado';
            return;
        }

        hideEmptyContent();

        if (resultCount) {
            resultCount.textContent = `${totalResults} arquivo${totalResults !== 1 ? 's' : ''} encontrado${totalResults !== 1 ? 's' : ''}`;
        }

        let html = '';

        files.forEach((file, index) => {
            const tags = file.tags.map(tag => `<span class="tag">${tag.name}</span>`).join(' ');
            const fileSize = formatFileSize(file.size);
            const fileDate = new Date(file.mtime * 1000).toLocaleDateString();
            const fileIcon = getFileIcon(file.name);

            html += `
                <tr class="file-row" 
                    data-file-id="${file.id}" 
                    data-file-path="${escapeHtml(file.path)}"
                    data-file-name="${escapeHtml(file.name)}"
                    data-mime-type="${escapeHtml(file.mimetype || '')}">
                    <td class="filename">
                        <div style="display: flex; align-items: center;">
                            <div class="file-icon ${fileIcon}"></div>
                            <div>
                                <div class="file-name">${escapeHtml(file.name)}</div>
                                <div class="file-path">${escapeHtml(file.path)}</div>
                            </div>
                        </div>
                    </td>
                    <td class="filesize">
                        <span class="file-size">${fileSize}</span>
                    </td>
                    <td class="date">
                        <span class="file-date">${fileDate}</span>
                    </td>
                    <td class="tags">
                        <div class="file-tags">${tags || '<span style="color: var(--color-text-light);">Nenhuma</span>'}</div>
                    </td>
                </tr>
            `;
        });

        fileList.innerHTML = html;

        // Adicionar event listeners para as linhas - Usar delegação de eventos
        fileList.addEventListener('click', handleFileClick);
    }

    // Função separada para lidar com cliques
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

    function openFile(fileId, filePath, fileName, mimeType) {
        console.log('Opening file:', { fileId, filePath, fileName, mimeType });

        // Para imagens, vídeos e PDFs, abrir com o viewer
        if (mimeType && (
            mimeType.startsWith('image/') ||
            mimeType.startsWith('video/') ||
            mimeType === 'application/pdf'
        )) {
            // Usar o método padrão do Nextcloud para abrir arquivos
            const openUrl = OC.generateUrl('/apps/files/?fileid={fileId}#openfile', {
                fileId: fileId
            });
            window.location.href = openUrl;
        } else {
            // Para outros arquivos, apenas navegar até eles
            const fileUrl = OC.generateUrl('/apps/files/?fileid={fileId}', {
                fileId: fileId
            });
            window.location.href = fileUrl;
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

    function openFile(fileId) {
        console.log('Opening file:', fileId);
        // window.location.href = OC.generateUrl('/apps/files/?fileid=' + fileId);
    }

    function setView(view) {
        currentView = view;

        if (view === 'list' && viewListBtn && viewGridBtn) {
            viewListBtn.classList.add('active');
            viewGridBtn.classList.remove('active');
        } else if (view === 'grid' && viewListBtn && viewGridBtn) {
            viewGridBtn.classList.add('active');
            viewListBtn.classList.remove('active');
        }
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
        document.querySelector('#emptycontent h2').textContent = 'Erro';
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

    showEmptyContent();
    document.querySelector('#emptycontent h2').textContent = 'Faça uma busca';
    document.querySelector('#emptycontent p').textContent = 'Use os filtros ao lado para buscar seus arquivos';

    setupTagAutocomplete();
});

function setupTagAutocomplete() {
    const tagsInput = document.getElementById('tags');
    let availableTags = [];

    // Buscar tags disponíveis
    fetch(OC.generateUrl('/apps/advancedsearch/api/tags'), {
        method: 'GET',
        headers: {
            'requesttoken': OC.requestToken
        }
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                availableTags = data.tags;
                setupAutocomplete(tagsInput, availableTags);
            }
        })
        .catch(error => {
            console.error('Erro ao buscar tags:', error);
        });
}

function setupAutocomplete(input, tags) {
    let currentFocus = -1;

    input.addEventListener('input', function () {
        const value = this.value;
        const lastComma = value.lastIndexOf(',');
        const currentTag = value.substring(lastComma + 1).trim();

        closeAllLists();

        if (!currentTag) return;

        const matches = tags.filter(tag =>
            tag.toLowerCase().includes(currentTag.toLowerCase())
        );

        if (matches.length > 0) {
            showSuggestions(input, matches, currentTag, lastComma);
        }
    });

    function showSuggestions(input, matches, currentTag, lastComma) {
        const suggestions = document.createElement('div');
        suggestions.className = 'autocomplete-suggestions';
        suggestions.style.cssText = `
            position: absolute;
            background: var(--color-main-background);
            border: 1px solid var(--color-border);
            border-radius: var(--border-radius);
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        `;

        matches.forEach(tag => {
            const suggestion = document.createElement('div');
            suggestion.className = 'autocomplete-suggestion';
            suggestion.textContent = tag;
            suggestion.style.cssText = `
                padding: 8px 12px;
                cursor: pointer;
                border-bottom: 1px solid var(--color-border);
            `;

            suggestion.addEventListener('click', function () {
                const beforeCurrent = input.value.substring(0, lastComma + 1);
                const afterCurrent = input.value.substring(lastComma + 1);
                input.value = beforeCurrent + (beforeCurrent ? ' ' : '') + tag + ', ';
                closeAllLists();
                input.focus();
            });

            suggestion.addEventListener('mouseenter', function () {
                this.style.background = 'var(--color-background-hover)';
            });

            suggestion.addEventListener('mouseleave', function () {
                this.style.background = '';
            });

            suggestions.appendChild(suggestion);
        });

        input.parentNode.appendChild(suggestions);

        // Posicionar sugestões
        const rect = input.getBoundingClientRect();
        suggestions.style.top = (rect.bottom + window.scrollY) + 'px';
        suggestions.style.left = rect.left + 'px';
        suggestions.style.width = rect.width + 'px';
    }

    function closeAllLists() {
        const suggestions = document.querySelectorAll('.autocomplete-suggestions');
        suggestions.forEach(el => el.remove());
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.autocomplete-suggestions') && e.target !== input) {
            closeAllLists();
        }
    });
}