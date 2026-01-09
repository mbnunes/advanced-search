
document.addEventListener('DOMContentLoaded', function () {

    // --- CONFIGURATION ---
    // List of file extensions to exclude from search results
    // You can add or remove extensions here as needed.
    const EXCLUDED_EXTENSIONS = [
        '.xls',
        '.pdf',
        '.txt',
        '.xlsx',
        '.doc',
        '.docx'
    ];
    // ---------------------

    // Elementos principais
    const searchBtn = document.getElementById('search-btn');
    const clearBtn = document.getElementById('clear-btn');
    const fileList = document.getElementById('fileList');
    const fileGrid = document.getElementById('fileGrid');
    const emptyContent = document.getElementById('emptycontent');
    const loading = document.getElementById('loading');
    const resultCount = document.getElementById('result-count');
    const viewListBtn = document.getElementById('view-list');
    const viewGridBtn = document.getElementById('view-grid');
    const fileTable = document.getElementById('filestable');
    const filenameInput = document.getElementById('filename'); // Reference for autocomplete

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
    let availableTags = []; // Store fetched tags

    // Fetch tags on load
    fetchTags();

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

    // Autocomplete Logic
    if (filenameInput) {
        const autocompleteList = document.createElement('div');
        autocompleteList.className = 'autocomplete-items';
        autocompleteList.style.cssText = `
            position: absolute;
            border: 1px solid #d4d4d4;
            border-bottom: none;
            border-top: none;
            z-index: 99;
            top: 100%;
            left: 0;
            right: 0;
            background-color: var(--color-main-background);
            max-height: 200px;
            overflow-y: auto;
            display: none;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border-radius: 0 0 4px 4px;
        `;
        
        // Wrap input in relative container if not already
        if (filenameInput.parentNode.style.position !== 'relative') {
             filenameInput.parentNode.style.position = 'relative';
        }
        filenameInput.parentNode.appendChild(autocompleteList);

        filenameInput.addEventListener('input', function(e) {
            const val = this.value;
            closeAllLists();
            if (!val) return false;

            // Check if we are typing a tag (last word starts with #)
            const cursorPosition = this.selectionStart;
            const textBeforeCursor = val.substring(0, cursorPosition);
            const lastHashIndex = textBeforeCursor.lastIndexOf('#');

            if (lastHashIndex !== -1) {
                // Check if there's a space after the hash before the cursor
                // If so, we might be typing a new word, unless it's inside quotes (complex to detect perfectly without full parser, but simple heuristic works)
                // For simple autocomplete: trigger if no space between # and cursor OR if we are inside a partial tag
                
                const query = textBeforeCursor.substring(lastHashIndex + 1);
                
                // If query contains a quote, we might be closing it or inside it. 
                // Let's keep it simple: Autocomplete works for unquoted typing to help insert quotes.
                if (query.includes('"')) return; 

                const matches = availableTags.filter(tag => tag.toLowerCase().startsWith(query.toLowerCase()));

                if (matches.length > 0) {
                    autocompleteList.style.display = 'block';
                    matches.forEach(tag => {
                        const item = document.createElement('div');
                        item.style.cssText = `
                            padding: 10px;
                            cursor: pointer;
                            border-bottom: 1px solid #d4d4d4;
                            background-color: var(--color-main-background);
                            color: var(--color-main-text);
                        `;
                        item.innerHTML = "<strong>" + tag.substr(0, query.length) + "</strong>";
                        item.innerHTML += tag.substr(query.length);
                        item.innerHTML += "<input type='hidden' value='" + tag + "'>";
                        
                        item.addEventListener('click', function(e) {
                            const selectedTag = this.getElementsByTagName("input")[0].value;
                            let tagToInsert = selectedTag;
                            
                            // Add quotes if tag has spaces
                            if (selectedTag.includes(' ')) {
                                tagToInsert = `"${selectedTag}"`;
                            }
                            
                            const textBeforeHash = textBeforeCursor.substring(0, lastHashIndex);
                            const textAfterCursor = val.substring(cursorPosition);
                            
                            filenameInput.value = textBeforeHash + '#' + tagToInsert + ' ' + textAfterCursor;
                            
                            closeAllLists();
                            filenameInput.focus();
                        });
                        
                        // Hover effect
                        item.addEventListener('mouseover', () => {
                            item.style.backgroundColor = 'var(--color-background-dark)';
                        });
                        item.addEventListener('mouseout', () => {
                            item.style.backgroundColor = 'var(--color-main-background)';
                        });

                        autocompleteList.appendChild(item);
                    });
                }
            }
        });

        // Close list when clicking outside
        document.addEventListener("click", function (e) {
            if (e.target !== filenameInput) {
                closeAllLists();
            }
        });

        function closeAllLists() {
            autocompleteList.innerHTML = '';
            autocompleteList.style.display = 'none';
        }
    }

    function fetchTags() {
        fetch(OC.generateUrl('/apps/advancedsearch/api/tags'))
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    availableTags = data.tags;
                    console.log('Tags loaded for autocomplete:', availableTags.length);
                }
            })
            .catch(err => console.error('Error fetching tags:', err));
    }

    function performSearch(page = 1) {
        console.log('performSearch chamado para página', page);
        let filenameInput = document.getElementById('filename').value;
        const hiddenTagsInput = document.getElementById('tags').value;
        let tagOperator = document.querySelector('input[name="tagOperator"]:checked').value;
        const fileType = document.getElementById('file-type').value;

        // Parse tags from filename input (e.g. "Name #tag1 #tag2" or Name #"Tag With Space")
        // STRATEGY: 
        // 1. Extract explicit #tags (remove from filename)
        // 2. For the remaining filename, generate ALL possible combinations of words
        // 3. Check if any combination exists in availableTags
        // 4. Add matches to parsedTags (but Keep filename!)

        let parsedTags = [];
        let explicitTags = [];
        
        // Helper to escape regex special characters
        const escapeRegExp = (string) => {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        };

        // 1. Extract Explicit #tags
        const tagRegex = /#"([^"]+)"|#([\w\u00C0-\u00FF-]+)/g;
        let match;
        // We need to reconstruct the clean filename by removing matches
        let cleanFilename = filenameInput;

        while ((match = tagRegex.exec(cleanFilename)) !== null) {
            const tag = match[1] || match[2];
            if (tag) {
                parsedTags.push(tag);
                explicitTags.push(tag);
            }
        }
        
        // Remove explicit tags from filename
        cleanFilename = cleanFilename.replace(tagRegex, '').trim();
        cleanFilename = cleanFilename.replace(/\s+/g, ' ');

        // 2. Tag Expansion (Combinatorial)
        if (cleanFilename && availableTags && availableTags.length > 0) {
            const tokens = cleanFilename.split(/\s+/).filter(t => t.length > 0);
            
            // Generate all non-empty combinations (Power Set)
            const combinations = [];
            const generateCombinations = (prefix, remainingTokens) => {
                for (let i = 0; i < remainingTokens.length; i++) {
                     const newToken = remainingTokens[i];
                     const newCombination = prefix ? prefix + ' ' + newToken : newToken;
                     combinations.push(newCombination);
                     
                     // Recurse with remaining tokens (to support non-adjacent like "BASQUETE SIDNEY")
                     generateCombinations(newCombination, remainingTokens.slice(i + 1));
                }
            };
            generateCombinations('', tokens);
            
            // Check availability and uniqueness
            const distinctCombinations = [...new Set(combinations)];
            const expandedTags = [];
            
            distinctCombinations.forEach(combo => {
                // Find case-insensitive match in availableTags
                const match = availableTags.find(t => t.toLowerCase() === combo.toLowerCase());
                if (match) {
                     expandedTags.push(match);
                }
            });
            
            if (expandedTags.length > 0) {
                console.log('DEBUG: Expanded Tags:', expandedTags);
                // Add to parsedTags
                parsedTags = [...parsedTags, ...expandedTags];
                
                // CRITICAL: If we added expanded tags, force 'OR' usage?
                // If we use AND, "BASQUETE MASCULINO" (Filename) + Tags["BASQUETE", "MASCULINO"] 
                // requires file to have ALL those tags. 
                // If user wants to find files with ANY of those tags (+ filename match), OR is better.
                // However, they also sent tagOperator: "AND" in their request example...
                
                // Let's deduce: If they have overlapping tags (BASQUETE and BASQUETE MASCULINO),
                // a file usually won't have both. So AND will fail.
                // Switching to OR automatically is safer for "Possibility Search".
                
                // Only switch if user hasn't explicitly set OR (which they can't easily on frontend right now) based on logic
                // But let's assume if expandedTags > 0, we imply "Try these tags".
                
                // IMPORTANT: If we have Explicit Tags (#), they should be AND? Or OR?
                // Mixed mode is hard. Let's set tagOperator to OR if we have ANY expanded tags.
                // This means (Universal Filename Search) AND (Has at least one of the tags).
                tagOperator = 'OR';
            }
        }
        
        // Combine with hidden tags input if any
        const hiddenTags = hiddenTagsInput ? hiddenTagsInput.split(',').map(tag => tag.trim()).filter(tag => tag) : [];
        const finalTags = [...new Set([...parsedTags, ...hiddenTags])]; // Unique tags

        // If we extracted tags from input, force AND operator as implied by "Search #tag"
        if (parsedTags.length > 0) {
            tagOperator = 'AND';
        }

        // Validação básica
        if (!cleanFilename && finalTags.length === 0 && !fileType) {
            showError('Por favor, insira pelo menos um critério de busca');
            return;
        }

        currentPage = page;
        const offset = (page - 1) * pageSize;
        const params = {
            filename: cleanFilename,
            tags: finalTags,
            tagOperator: tagOperator,
            fileType: fileType,
            limit: pageSize,
            offset: offset,
            useFullTextSearch: true,
            excludedExtensions: EXCLUDED_EXTENSIONS // Send exclusion list to backend
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
            paginationInfo.textContent = `${start}-${end} de ${totalResults} resultados`;
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
                
                // Garantir que a aba de metadados esteja registrada
                if (typeof ensureMetadataTabRegistered === 'function') {
                    ensureMetadataTabRegistered();
                }
                
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
        if (fileGrid) fileGrid.innerHTML = '';
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
        if (fileGrid) fileGrid.innerHTML = '';

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

        if (fileTable) fileTable.classList.remove('hidden');
        if (fileGrid) fileGrid.classList.add('hidden');
        
        if (fileTable) {
            fileTable.classList.add('list-view');
            fileTable.classList.remove('grid-view');
        }
    }

    function displayGridView(files) {
        console.log('displayGridView iniciado. Arquivos:', files.length);
        
        if (!fileGrid) {
            console.error('ERRO CRÍTICO: fileGrid não encontrado!');
            return;
        }

        // Garantir que a lista está visível
        fileGrid.classList.remove('hidden');
        if (fileTable) fileTable.classList.add('hidden');

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
            try {
                if (!file || !file.name) {
                    console.warn('Arquivo inválido encontrado:', file);
                    continue;
                }

                // Lógica de exibição: Imagens = Thumbnail; Vídeo/Áudio = Ícone de Play
                const isImage = file.mimetype?.startsWith('image/');
                const isVideo = file.mimetype?.startsWith('video/');
                const isAudio = file.mimetype?.startsWith('audio/') || file.name.toLowerCase().endsWith('.cfa');
                const isPdf = file.mimetype === 'application/pdf';
                
                // Imagens, Vídeos e PDFs tentam carregar thumbnail
                const hasThumbnail = isImage || isVideo || isPdf;

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
                        color: var(--color-text-light);
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
                    overflow: hidden;
                `;

                if (hasThumbnail) {
                    const thumbnailUrl = OC.generateUrl('/core/preview?fileId=' + file.id + '&x=250&y=250&a=true');
                    
                    const img = document.createElement('img');
                    img.src = thumbnailUrl;
                    img.style.cssText = `
                        width: 100%;
                        height: 100%;
                        object-fit: cover;
                        position: absolute;
                        top: 0;
                        left: 0;
                    `;
                    
                    // Error handling: fallback to icon
                    img.onerror = function() {
                        // console.warn('Falha ao carregar miniatura para:', file.name); // Silenciar aviso comum
                        this.style.display = 'none';
                        
                        // Se for imagem e falhar, mostra ícone. Se for vídeo e falhar, o ícone de play (adicionado abaixo) já serve, mas precisamos de um fundo ou ícone de arquivo atrás.
                        // Se for imagem e falhar, mostra ícone. Se for vídeo e falhar, o ícone de play (adicionado abaixo) já serve, mas precisamos de um fundo ou ícone de arquivo atrás.
                        if (isImage || isPdf) {
                            const fileIcon = document.createElement('div');
                            // Para PDF, se falhar o thumbnail, usa ícone genérico
                            const iconClass = isPdf ? 'icon-filetype-pdf' : getFileIcon(file.name); // Tentar usar classe específica se disponível no CSS do NC, senão o helper mapeia
                            
                            // Se getFileIcon retornar algo genérico para PDF, podemos forçar imagem se quisermos ou deixar texto
                            // Vamos usar o helper corrigido que será injetado abaixo
                            
                            fileIcon.className = `file-icon ${getFileIcon(file.name)}`;
                            fileIcon.style.fontSize = '48px';
                            // Limpar conteúdo anterior (img oculta) para centralizar ícone
                            thumbnailArea.innerHTML = ''; 
                            thumbnailArea.appendChild(fileIcon);
                        }
                    };

                    thumbnailArea.appendChild(img);
                }

                if (isVideo || isAudio) {
                    // Ícone de PLAY para vídeo e áudio (Overlay)
                    const playIcon = document.createElement('div');
                    playIcon.innerHTML = '▶'; 
                    playIcon.style.cssText = `
                        font-size: 48px;
                        color: var(--color-text-maxcontrast);
                        background: rgba(0,0,0,0.5);
                        width: 64px;
                        height: 64px;
                        border-radius: 50%;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        padding-left: 6px;
                        border: 2px solid var(--color-text-maxcontrast);
                        z-index: 2; /* Ficar acima da imagem */
                        position: relative; /* Para centralizar no flex container */
                    `;
                    thumbnailArea.appendChild(playIcon);
                } 
                
                if (!hasThumbnail && !isVideo && !isAudio) {
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
            } catch (err) {
                console.error('Erro ao renderizar arquivo:', file, err);
            }
        }



        console.log('Limpando fileGrid e adicionando gridContainer com', gridContainer.children.length, 'cards');
        fileGrid.innerHTML = '';
        fileGrid.appendChild(gridContainer);
        console.log('fileGrid atualizado.');
    }

    function playVideo(file) {
        // Criar modal
        const modal = document.createElement('div');
        modal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.9);
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        `;

        // Botão fechar
        const closeBtn = document.createElement('button');
        closeBtn.innerHTML = '×';
        closeBtn.style.cssText = `
            position: absolute;
            top: 20px;
            right: 20px;
            background: none;
            border: none;
            color: white;
            font-size: 40px;
            cursor: pointer;
            z-index: 10001;
        `;
        closeBtn.onclick = () => document.body.removeChild(modal);
        modal.appendChild(closeBtn);

        // Container do vídeo
        const videoContainer = document.createElement('div');
        videoContainer.style.cssText = `
            width: 80%;
            max-width: 1000px;
            max-height: 80vh;
            background: black;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 0 20px rgba(0,0,0,0.5);
        `;

        const video = document.createElement('video');
        video.controls = true;
        video.autoplay = true;
        video.style.width = '100%';
        video.style.height = '100%';
        
        // URL de download direto
        const downloadUrl = OC.generateUrl('/apps/files/download/' + file.id);
        video.src = downloadUrl;

        // Tratamento de erro
        video.onerror = () => {
            console.error('Erro ao reproduzir vídeo:', file.name);
            videoContainer.innerHTML = `
                <div style="padding: 40px; text-align: center; color: white;">
                    <p>Não foi possível reproduzir este formato de vídeo (${file.mimetype}) no navegador.</p>
                    <a href="${downloadUrl}" class="button primary" download>Baixar Arquivo</a>
                </div>
            `;
        };

        videoContainer.appendChild(video);
        modal.appendChild(videoContainer);
        
        // Título
        const title = document.createElement('div');
        title.textContent = file.name;
        title.style.cssText = `
            color: white;
            margin-top: 16px;
            font-size: 18px;
        `;
        modal.appendChild(title);

        // Fechar ao clicar fora
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                document.body.removeChild(modal);
            }
        });

        document.body.appendChild(modal);
    }

    function handleFileClick(event) {
        console.log('handleFileClick chamado');
        const row = event.target.closest('.file-row') || event.target.closest('.file-card');
        if (!row) return;

        const file = row.fileData;
        if (!file) return;

        console.log('Arquivo clicado:', file);

        const isImage = file.mimetype.startsWith('image/');
        const isVideo = file.mimetype.startsWith('video/');
        const isPdf = file.mimetype === 'application/pdf';

        if (isVideo) {
            console.log('É vídeo. Tentando reproduzir com modal.');
            playVideo(file);
            return;
        }

        if (isImage || isPdf) {
            console.log('É imagem ou PDF. Tentando abrir Viewer.');
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
                
                // Garantir que a aba de metadados esteja registrada
                if (typeof ensureMetadataTabRegistered === 'function') {
                    ensureMetadataTabRegistered();
                }
                
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

    function showLoading() {
        if (loading) loading.classList.remove('hidden');
        if (fileList) fileList.classList.add('hidden');
        if (fileGrid) fileGrid.classList.add('hidden');
        if (emptyContent) emptyContent.classList.add('hidden');
        if (pagination) pagination.classList.add('hidden');
    }

    function hideLoading() {
        if (loading) loading.classList.add('hidden');
        // Não removemos hidden de fileList/fileGrid aqui, pois displayResults fará isso
    }

    function showEmptyContent() {
        if (emptyContent) emptyContent.classList.remove('hidden');
        if (fileList) fileList.innerHTML = '';
        if (fileGrid) fileGrid.innerHTML = '';
        if (pagination) pagination.classList.add('hidden');
    }

    function hideEmptyContent() {
        if (emptyContent) emptyContent.classList.add('hidden');
    }

    function showError(message) {
        if (resultCount) {
            resultCount.innerHTML = `<span style="color: var(--color-error);">${message}</span>`;
        }
        showEmptyContent();
        const emptyTitle = document.querySelector('#emptycontent h2');
        const emptyText = document.querySelector('#emptycontent p');
        if (emptyTitle) emptyTitle.textContent = 'Erro';
        if (emptyText) emptyText.textContent = message;
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

    // Helper function to get file icon class based on extension
    function getFileIcon(filename) {
        if (!filename) return 'icon-file';
        var extension = filename.split('.').pop().toLowerCase();
        
        switch (extension) {
            case 'pdf':
                return 'icon-filetype-image'; // Or icon-file-pdf if available, but image usually triggers viewer
            case 'jpg':
            case 'jpeg':
            case 'png':
            case 'gif':
            case 'webp':
            case 'svg':
            case 'bmp':
                return 'icon-filetype-image';
            case 'mp4':
            case 'mkv':
            case 'avi':
            case 'mov':
            case 'webm':
                return 'icon-filetype-video';
            case 'mp3':
            case 'wav':
            case 'flac':
            case 'ogg':
            case 'm4a':
            case 'acc':
                return 'icon-filetype-audio';
            case 'txt':
            case 'md':
                return 'icon-filetype-text';
            case 'doc':
            case 'docx':
            case 'odt':
                return 'icon-filetype-document';
            case 'xls':
            case 'xlsx':
            case 'ods':
                return 'icon-filetype-spreadsheet';
            case 'ppt':
            case 'pptx':
            case 'odp':
                return 'icon-filetype-presentation';
            case 'zip':
            case 'rar':
            case 'tar':
            case 'gz':
            case '7z':
                return 'icon-filetype-archive';
            default:
                return 'icon-file';
        }
    }
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

// Função para registrar a aba (pode ser chamada sob demanda)
var ensureMetadataTabRegistered = function() {
    console.log('Search: Ensuring MetadataTab is registered...');
    
    if (window.OCA && window.OCA.Files && window.OCA.Files.Sidebar) {
        // Evitar registrar duas vezes
        if (OCA.Files.Sidebar.Tab.prototype._advancedSearchRegistered) {
             console.log('Search: MetadataTab already registered.');
             return true;
        }
        
        console.log('Search: Registering MetadataTab now...');
        
        console.log('Search: Registering MetadataTab now...');
        
        class MetadataTab extends OCA.Files.Sidebar.Tab {
            constructor() {
                super({
                    id: 'advancedSearchMetadata',
                    name: 'Metadados',
                    icon: 'icon-info'
                });
                this._advancedSearchRegistered = true;
                console.log('MetadataTab initialized');
            }

            enabled(fileInfo) {
                console.log('MetadataTab.enabled called');
                return true;
            }

            mount(el, fileInfo, context) {
                console.log('MetadataTab.mount called');
                var $el = $(el);
                $el.addClass('advanced-search-metadata-tab');
                $el.html('<div class="icon-loading"></div>');

                // Tentar encontrar os dados do arquivo
                var fileId = fileInfo.id;
                var fileData = null;
                
                // Buscar no DOM
                var row = document.querySelector('.file-row[data-id="' + fileId + '"]') || 
                          document.querySelector('.file-card[data-id="' + fileId + '"]');
                if (row && row.fileData) {
                    fileData = row.fileData;
                } else {
                    // Fallback: procurar em todos
                    var rows = document.querySelectorAll('.file-row, .file-card');
                    for (var i = 0; i < rows.length; i++) {
                        if (rows[i].fileData && rows[i].fileData.id == fileId) {
                            fileData = rows[i].fileData;
                            break;
                        }
                    }
                }

                if (fileData) {
                    this._renderContent($el, fileData);
                } else {
                    // Fallback básico
                    this._renderContent($el, {
                        name: fileInfo.name,
                        path: fileInfo.path || fileInfo.dir + '/' + fileInfo.name,
                        size: fileInfo.size,
                        mtime: fileInfo.mtime ? fileInfo.mtime / 1000 : null,
                        mimetype: fileInfo.mimetype
                    });
                }
            }

            update(fileInfo) {
                console.log('MetadataTab.update called');
            }
            
            _renderContent($el, data) {
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
            }

            _renderRow(label, value) {
                if (!value) return '';
                return '<div class="metadata-row">' +
                       '<div class="metadata-label">' + escapeHtml(label) + '</div>' +
                       '<div class="metadata-value" title="' + escapeHtml(value) + '">' + escapeHtml(value) + '</div>' +
                       '</div>';
            }
        }

        try {
            OCA.Files.Sidebar.registerTab(new MetadataTab());
            OCA.Files.Sidebar.Tab.prototype._advancedSearchRegistered = true;
            console.log('Search: MetadataTab registered successfully');
            return true;
        } catch (e) {
            console.error('Search: Error registering MetadataTab:', e);
            return false;
        }
    } else {
        console.error('Search: OCA.Files.Sidebar NOT found when ensuring registration.');
        return false;
    }
};

// Tentar registrar no load também, por garantia
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ensureMetadataTabRegistered);
} else {
    ensureMetadataTabRegistered();
}