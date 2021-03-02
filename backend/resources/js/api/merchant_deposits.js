import fetch from '@/utils/fetch'

export function getDeposits( data ) {
    return fetch({
        url: 'merchant_deposits',
        method: 'get',
        params:data
    });
}

export function getDetail( id ) {
    return fetch({
        url: 'merchant_deposits/detail',
        method: 'get',
        params:{id:id}
    });
}

export function putDeal( data ) {
    return fetch({
        url: 'merchant_deposits/deal',
        method: 'put',
        data
    });
}

export function putVerify( data ) {
    return fetch({
        url: 'deposits/verify',
        method: 'put',
        data
    });
}


