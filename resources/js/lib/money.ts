/**
 * Money arrives from the server as exact decimal strings (nine decimal places).
 * It's only ever converted to a number for display, never for arithmetic.
 */
export function formatUsd(amount: string | null, decimals = 4): string {
    if (amount === null) {
        return '–';
    }

    const value = Number(amount);

    if (value > 0 && value < 10 ** -decimals) {
        return `< $${(10 ** -decimals).toFixed(decimals)}`;
    }

    return `$${value.toFixed(decimals)}`;
}

export function exactUsd(amount: string | null): string {
    return amount === null ? '' : `$${amount} exactly`;
}
