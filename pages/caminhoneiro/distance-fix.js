// Função para calcular distância inicial aproximada
function calcularDistanciaInicial() {
    if (!currentPosition) {
        // Se a localização atual ainda não estiver disponível, tentar novamente após um atraso
        setTimeout(calcularDistanciaInicial, 1000);
        return;
    }
    
    try {
        const origemLatEl = document.getElementById('origem_lat');
        const origemLngEl = document.getElementById('origem_lng');
        const destinoLatEl = document.getElementById('destino_lat');
        const destinoLngEl = document.getElementById('destino_lng');
        
        // Verificar se todos os elementos existem
        if (!origemLatEl || !origemLngEl || !destinoLatEl || !destinoLngEl) {
            console.error('Elementos de coordenadas não encontrados');
            return;
        }
        
        const origem = { 
            lat: parseFloat(origemLatEl.value), 
            lng: parseFloat(origemLngEl.value)
        };
        
        const destino = { 
            lat: parseFloat(destinoLatEl.value), 
            lng: parseFloat(destinoLngEl.value)
        };
        
        // Verificar se as coordenadas são válidas
        if (isNaN(origem.lat) || isNaN(origem.lng) || isNaN(destino.lat) || isNaN(destino.lng)) {
            console.error('Coordenadas inválidas');
            return;
        }
        
        // Determinar o destino atual com base na etapa
        let destinoAtual;
        
        try {
            destinoAtual = (typeof etapaViagem !== 'undefined' && etapaViagem === 'entrega') ? destino : origem;
        } catch (e) {
            // Se etapaViagem não estiver definida, assumir origem
            destinoAtual = origem;
        }
        
        let distanciaColeta, distanciaEntrega;
        
        // Usar a biblioteca de geometria do Google Maps se disponível
        if (typeof google !== 'undefined' && google.maps && google.maps.geometry) {
            try {
                const fromPos = new google.maps.LatLng(currentPosition.lat, currentPosition.lng);
                const toOrigem = new google.maps.LatLng(origem.lat, origem.lng);
                const toDestino = new google.maps.LatLng(destino.lat, destino.lng);
                
                // Calcular a distância em metros e converter para km
                distanciaColeta = google.maps.geometry.spherical.computeDistanceBetween(fromPos, toOrigem) / 1000;
                distanciaEntrega = google.maps.geometry.spherical.computeDistanceBetween(toOrigem, toDestino) / 1000;
            } catch (geoError) {
                console.warn('Erro ao usar geometria do Google Maps:', geoError);
                // Fallback para o cálculo manual
                distanciaColeta = calcDistanceKm(
                    currentPosition.lat, 
                    currentPosition.lng, 
                    origem.lat, 
                    origem.lng
                );
                distanciaEntrega = calcDistanceKm(
                    origem.lat, 
                    origem.lng, 
                    destino.lat, 
                    destino.lng
                );
            }
        } else {
            // Função para calcular distância aproximada em quilômetros (fórmula de Haversine)
            distanciaColeta = calcDistanceKm(
                currentPosition.lat, 
                currentPosition.lng, 
                origem.lat, 
                origem.lng
            );
            distanciaEntrega = calcDistanceKm(
                origem.lat, 
                origem.lng, 
                destino.lat, 
                destino.lng
            );
        }
        
        // Atualizar elementos UI com as distâncias calculadas
        updateDistanceUI(distanciaColeta, distanciaEntrega);
        
    } catch (error) {
        console.error('Erro ao calcular distância:', error);
    }
}

// Função para atualizar a UI com as distâncias calculadas
function updateDistanceUI(distanciaColeta, distanciaEntrega) {
    // Verificar se os elementos existem antes de atualizar
    const elementoDistanciaColeta = document.getElementById('distancia_coleta');
    if (elementoDistanciaColeta) {
        elementoDistanciaColeta.textContent = Math.round(distanciaColeta) + ' km';
    }
    
    const elementoDistanciaEntrega = document.getElementById('distancia_entrega');
    if (elementoDistanciaEntrega) {
        elementoDistanciaEntrega.textContent = Math.round(distanciaEntrega) + ' km';
    }
    
    // Usamos uma estimativa de tempo baseada em uma velocidade média de 70 km/h
    const tempoEstimado = distanciaEntrega / 70;
    const horas = Math.floor(tempoEstimado);
    const minutos = Math.round((tempoEstimado - horas) * 60);
    
    const elementoTempo = document.getElementById('tempo');
    if (elementoTempo) {
        elementoTempo.textContent = horas + ' horas ' + minutos + ' min';
    }
    
    // Atualizar também o painel de navegação se existir
    const navDistance = document.getElementById('nav-distance');
    if (navDistance) {
        // Determinar qual distância mostrar com base na etapa
        try {
            const distancia = (typeof etapaViagem !== 'undefined' && etapaViagem === 'entrega') ? 
                distanciaEntrega : distanciaColeta;
            navDistance.textContent = Math.round(distancia) + ' km';
        } catch (e) {
            // Se etapaViagem não estiver definida, mostrar distância de coleta
            navDistance.textContent = Math.round(distanciaColeta) + ' km';
        }
    }
    
    const navTime = document.getElementById('nav-time');
    if (navTime) {
        navTime.textContent = horas + 'h ' + minutos + 'min';
    }
}

// Função para calcular distância aproximada em quilômetros (fórmula de Haversine)
function calcDistanceKm(lat1, lon1, lat2, lon2) {
    const R = 6371; // Raio da Terra em km
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = 
        Math.sin(dLat/2) * Math.sin(dLat/2) +
        Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * 
        Math.sin(dLon/2) * Math.sin(dLon/2); 
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a)); 
    const distance = R * c;
    return distance;
} 