document.addEventListener('DOMContentLoaded', function () {
    const searchBtn = document.getElementById('search-btn');
    const clearBtn = document.getElementById('clear-btn');
    const fileList = document.getElementById('fileList');
    const emptyContent = document.getElementById('emptycontent');
    const loading = document.getElementById('loading');
    const resultCount = document.getElementById('result-count');
    const viewListBtn = document.getElementById('view-list');
    const viewGridBtn = document.getElementById('view-grid');

    let currentView = 'list';

    // Event listeners
    searchBtn.addEventListener('click', performSearch);
    clearBtn.addEventListener('click', clearSearch);
    viewListBtn.addEventListener('click', () => setView('list'));
    viewGridBtn.addEventListener('click', () => setView('grid'));

    // Busca ao pressionar Enter
    document.addEventListener('keypress', function (e) {
        if (e.key === 'Enter') {
            performSearch();
        }
    });

    function performSearch() {
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

    // Mostrar loading
    showLoading();

    // Construir URL com query parameters
    const params = new URLSearchParams();
    if (filename) params.append('filename', filename);
    if (fileType) params.append('fileType', fileType);
    params.append('tagOperator', tagOperator);
    tags.forEach(tag => params.append('tags[]', tag));

    fetch(OC.generateUrl('/apps/advancedsearch/api/search') + '?' + params.toString(), {
        method: 'GET',
        headers: {
            'requesttoken': OC.requestToken
        }
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
            displayResults(data.files);
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

    function clearSearch() {
        document.getElementById('filename').value = '';
        document.getElementById('tags').value = '';
        document.getElementById('file-type').value = '';
        document.getElementById('tag-and').checked = true;

        fileList.innerHTML = '';
        resultCount.textContent = '';
        showEmptyContent();
    }

    function displayResults(files) {
        hideEmptyContent();

        if (files.length === 0) {
            showEmptyContent();
            resultCount.textContent = 'Nenhum resultado encontrado';
            return;
        }

        resultCount.textContent = `${files.length} arquivo${files.length !== 1 ? 's' : ''} encontrado${files.length !== 1 ? 's' : ''}`;

        let html = '';

        files.forEach(file => {
            const tags = file.tags.map(tag => `<span class="tag">${tag.name}</span>`).join(' ');
            const fileSize = formatFileSize(file.size);
            const fileDate = new Date(file.mtime * 1000).toLocaleDateString();
            const fileIcon = getFileIcon(file.name);

            html += `
                <tr class="file-row" data-file-id="${file.id}">
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

        // Adicionar event listeners para as linhas
        document.querySelectorAll('.file-row').forEach(row => {
            row.addEventListener('click', function () {
                const fileId = this.getAttribute('data-file-id');
                openFile(fileId);
            });
        });
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
        // Implementar abertura do arquivo
        console.log('Opening file:', fileId);
    }

    function setView(view) {
        currentView = view;

        if (view === 'list') {
            viewListBtn.classList.add('active');
            viewGridBtn.classList.remove('active');
            // Implementar view de lista
        } else {
            viewGridBtn.classList.add('active');
            viewListBtn.classList.remove('active');
            // Implementar view de grid
        }
    }

    function showLoading() {
        loading.classList.remove('hidden');
        emptyContent.classList.add('hidden');
        fileList.innerHTML = '';
    }

    function hideLoading() {
        loading.classList.add('hidden');
    }

    function showEmptyContent() {
        emptyContent.classList.remove('hidden');
        fileList.innerHTML = '';
    }

    function hideEmptyContent() {
        emptyContent.classList.add('hidden');
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

    // Inicializar
    showEmptyContent();
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
    
    input.addEventListener('input', function() {
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
            
            suggestion.addEventListener('click', function() {
                const beforeCurrent = input.value.substring(0, lastComma + 1);
                const afterCurrent = input.value.substring(lastComma + 1);
                input.value = beforeCurrent + (beforeCurrent ? ' ' : '') + tag + ', ';
                closeAllLists();
                input.focus();
            });
            
            suggestion.addEventListener('mouseenter', function() {
                this.style.background = 'var(--color-background-hover)';
            });
            
            suggestion.addEventListener('mouseleave', function() {
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
    
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.autocomplete-suggestions') && e.target !== input) {
            closeAllLists();
        }
    });
}