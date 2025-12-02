
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

    let currentView = 'grid';
    let currentPage = 1;
    let totalResults = 0;
    let pageSize = 25;
    let lastSearchParams = null;
    let fullTextSearchAvailable = true;
    let lastSearchType = 'traditional';

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

        currentPage = page;
        const offset = (page - 1) * pageSize;
        const params = {
            filename: filename,
            tags: tags,
            tagOperator: tagOperator,
            fileType: fileType,
            limit: pageSize,
            offset: offset,
            useFullTextSearch: true
        };

        console.log('--- INICIANDO BUSCA ---');
        console.log('Parâmetros:', params);

        // Mostrar loading
        showLoading();

        // Salvar parâmetros da última busca
        lastSearchParams = params;

        console.log('Enviando requisição para API:', params);

        fetch(OC.generateUrl('/apps/advancedsearch/api/search'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken
            },
            body: JSON.stringify(params)
        })
            .then(response => {
                console.log('Resposta recebida. Status:', response.status);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Dados decodificados:', data);
                hideLoading();
                if (data.success) {
                    console.log('Busca com sucesso. Arquivos encontrados:', data.files ? data.files.length : 0);
                    
                    // Salvar tipo de busca usado e disponibilidade
                    lastSearchType = data.searchType || 'traditional';
                    fullTextSearchAvailable = data.fullTextSearchAvailable || false;

                    console.log('Tipo de busca reportado pelo backend:', data.searchInfo ? data.searchInfo.actualSearchType : 'N/A');
                    console.log('Debug do backend:', data.debug);

                    // MOSTRAR RESULTADOS IMEDIATAMENTE
                    displayResults(data.files || [], offset);
                    updatePagination();
                    updateSearchInfo(data);

                    // Para obter o total real, fazer uma busca sem limite em background
                    console.log('Iniciando contagem total em background...');
                    getTotalCount(lastSearchParams).then(total => {
                        console.log('Contagem total finalizada:', total);
                        totalResults = total;
                        // Atualizar apenas a info de paginação/total quando terminar
                        updatePagination();
                        if (resultCount) {
                            resultCount.textContent = `${totalResults} arquivo${totalResults !== 1 ? 's' : ''} encontrado${totalResults !== 1 ? 's' : ''}`;
                        }
                    });
                } else {
                    console.error('Erro reportado pela API:', data.message);
                    showError(data.message || 'Erro desconhecido na busca');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('ERRO FATAL NA BUSCA:', error);
                showError('Erro de conexão. Tente novamente.');
            });
    }

    function updateSearchInfo(data) {
        // Atualizar informações sobre o tipo de busca
        if (resultCount) {
            let searchInfo = '';
            if (data.searchType === 'fulltext') {
                searchInfo = ' (busca avançada)';
            } else if (data.searchType === 'traditional') {
                searchInfo = ' (busca tradicional)';
            }

            const currentText = resultCount.textContent;
            if (currentText && !currentText.includes('(busca')) {
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

    function handleFileClick(event) {
        console.log('handleFileClick chamado');
        // Encontrar a linha clicada
        const row = event.target.closest('.file-row') || event.target.closest('.file-card');
        if (!row) {
            console.log('Nenhuma linha encontrada');
            return;
        }

        const file = row.fileData;
        if (!file) {
            console.log('Nenhum dado de arquivo encontrado na linha');
            return;
        }

        console.log('Arquivo clicado:', file);

        const isImage = file.mimetype.startsWith('image/');
        const isVideo = file.mimetype.startsWith('video/');

        if (isImage || isVideo) {
            console.log('É imagem ou vídeo. Tentando abrir Viewer.');
            // Abrir com Viewer nativo
            if (window.OCA && window.OCA.Viewer) {
                console.log('OCA.Viewer disponível');
                
                let cleanPath = file.path;
                
                // Lógica de limpeza de caminho
                // O Viewer precisa do caminho relativo ao root do usuário (ex: /Fotos/img.jpg)
                // O file.path pode vir como /files/USER/Fotos/img.jpg
                
                // Tentar usar o OC.currentUser se disponível
                if (window.OC && window.OC.currentUser) {
                    const userFilesPrefix = '/' + window.OC.currentUser + '/files';
                    if (cleanPath.includes(userFilesPrefix)) {
                        cleanPath = cleanPath.substring(cleanPath.indexOf(userFilesPrefix) + userFilesPrefix.length);
                    }
                }
                
                // Fallback: se ainda parecer ter prefixos de sistema, tentar limpar
                // Ex: /admin/files/Pasta -> /Pasta
                const parts = cleanPath.split('/');
                if (parts.length > 3 && (parts[1] === 'files' || parts[2] === 'files')) {
                     // Heurística: encontrar onde está o 'files' e pegar o que vem depois do user
                     const filesIndex = parts.indexOf('files');
                     if (filesIndex !== -1 && parts.length > filesIndex + 2) {
                         // parts[filesIndex] é 'files'
                         // parts[filesIndex+1] é o usuário
                         // O resto é o caminho
                         const potentialPath = '/' + parts.slice(filesIndex + 2).join('/');
                         // Só usar se for mais curto que o original
                         if (potentialPath.length < cleanPath.length) {
                             cleanPath = potentialPath;
                         }
                     }
                }

                console.log('Caminho limpo para o Viewer:', cleanPath);
                
                // Tentar abrir o Viewer
                // Passar o fileId também pode ajudar se o Viewer suportar
                try {
                    // O método open requer um objeto na nova versão
                    console.log('Chamando OCA.Viewer.open com objeto:', { path: cleanPath, fileId: file.id });
                    window.OCA.Viewer.open({
                        path: cleanPath,
                        fileId: file.id,
                        sidebar: true
                    });
                } catch (e) {
                    console.error('Erro ao chamar OCA.Viewer.open:', e);
                    // Fallback para nova aba
                    window.open(OC.generateUrl('/apps/files/?fileid=' + file.id), '_blank');
                }
            } else {
                console.error('OCA.Viewer não disponível (window.OCA.Viewer undefined)');
                console.log('window.OCA:', window.OCA);
                // Fallback
                window.open(OC.generateUrl('/apps/files/?fileid=' + file.id), '_blank');
            }
        } else {
            console.log('Não é imagem/vídeo. Redirecionando para Files.');
            // Para outros arquivos, abrir na visualização de arquivos
            window.location.href = OC.generateUrl('/apps/files/?fileid=' + file.id);
        }
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
        lastSearchType = 'traditional';
    }

    function displayResults(files, offset) {
        if (!files || files.length === 0 && currentPage === 1) {
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
                searchIndicator = `<span class="search-score" title="Relevância: ${file.score.toFixed(2)}">⭐</span>`;
            }

            const row = document.createElement('tr');
            row.className = 'file-row';
            row.fileData = file; // Anexar dados do arquivo ao elemento DOM
            
            row.innerHTML = `
                <td class="filename">
                    <div style="display: flex; align-items: center;">
                        <div class="file-icon ${fileIcon}"></div>
                        <div>
                            <div class="file-name">${escapeHtml(file.name)} ${searchIndicator}</div>
                            <div class="file-path">${escapeHtml(file.path)}</div>
                            ${file.excerpt ? `<div class="file-excerpt" style="font-size: 12px; color: var(--color-text-lighter); margin-top: 4px;">${escapeHtml(file.excerpt)}</div>` : ''}
                        </div>
                       style="text-decoration: none; color: inherit; display: block;">
                        <span class="file-date">${fileDate}</span>
                    </a>
                </td>
                <td class="tags">
                    <div class="file-tags">${tags || '<span style="color: var(--color-text-light);">Nenhuma</span>'}</div>
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
                scoreIndicator.title = `Relevância: ${file.score.toFixed(2)}`;
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

            fileCard.fileData = file; // Anexar dados do arquivo
            fileCard.addEventListener('click', handleFileClick);

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
                noTags.textContent = 'Nenhuma tag';
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
                    console.error('Erro ao recarregar resultados:', error);
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

    // Inicialização
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

// Registro da aba personalizada de Metadados
document.addEventListener('DOMContentLoaded', function() {
    console.log('Advanced Search: DOMContentLoaded fired. Checking for OCA.Files.Sidebar...');
    if (window.OCA && window.OCA.Files && window.OCA.Files.Sidebar) {
        console.log('Advanced Search: OCA.Files.Sidebar found. Registering MetadataTab...');
        var MetadataTab = OCA.Files.Sidebar.Tab.extend({
            _file: null,

            id: 'advancedSearchMetadata',
            name: 'Metadados',
            icon: 'icon-info',

            initialize: function() {
                console.log('MetadataTab initialized');
                this._fileDataCache = {};
            },
            
            // ... rest of the code


            enabled: function(fileInfo) {
                return true;
            },

            mount: function(el, fileInfo, context) {
                this._file = fileInfo;
                var self = this;
                var $el = $(el);
                
                $el.addClass('advanced-search-metadata-tab');
                $el.html('<div class="icon-loading"></div>');

                var fileId = fileInfo.id;
                var fileData = this._findFileData(fileId);

                if (fileData) {
                    this._renderContent($el, fileData);
                } else {
                    this._renderContent($el, {
                        name: fileInfo.name,
                        path: fileInfo.path || fileInfo.dir + '/' + fileInfo.name,
                        size: fileInfo.size,
                        mtime: fileInfo.mtime ? fileInfo.mtime / 1000 : null,
                        mimetype: fileInfo.mimetype
                    });
                }
            },

            update: function(fileInfo) {
                this._file = fileInfo;
            },

            _findFileData: function(fileId) {
                var row = document.querySelector('.file-row[data-id="' + fileId + '"]') || 
                          document.querySelector('.file-card[data-id="' + fileId + '"]');
                
                if (row && row.fileData) {
                    return row.fileData;
                }
                
                var rows = document.querySelectorAll('.file-row, .file-card');
                for (var i = 0; i < rows.length; i++) {
                    if (rows[i].fileData && rows[i].fileData.id == fileId) {
                        return rows[i].fileData;
                    }
                }
                
                return null;
            },

            _renderContent: function($el, data) {
                var html = '<div class="metadata-list">';
                
                html += this._renderRow('Nome', data.name);
                
                var cleanPath = data.path;
                if (OC && OC.currentUser) {
                    var userPrefix = '/' + OC.currentUser + '/files';
                    if (cleanPath && cleanPath.includes(userPrefix)) {
                        cleanPath = cleanPath.substring(cleanPath.indexOf(userPrefix) + userPrefix.length);
                    }
                }
                html += this._renderRow('Caminho', cleanPath);
                
                html += this._renderRow('Tamanho', formatFileSize(data.size));
                
                if (data.mtime) {
                    html += this._renderRow('Modificado', new Date(data.mtime * 1000).toLocaleString());
                }
                
                if (data.score) {
                    html += this._renderRow('Relevância', data.score.toFixed(2));
                }
                
                if (data.tags && data.tags.length > 0) {
                    var tagsHtml = data.tags.map(function(t) { return t.name; }).join(', ');
                    html += this._renderRow('Tags', tagsHtml);
                } else {
                    html += this._renderRow('Tags', 'Sem tags');
                }
                
                html += '</div>';
                $el.html(html);
            },

            _renderRow: function(label, value) {
                if (!value) return '';
                return '<div class="metadata-row">' +
                       '<div class="metadata-label">' + escapeHtml(label) + '</div>' +
                       '<div class="metadata-value" title="' + escapeHtml(value) + '">' + escapeHtml(value) + '</div>' +
                       '</div>';
            }
        });

        OCA.Files.Sidebar.registerTab(new MetadataTab());
    }
});

