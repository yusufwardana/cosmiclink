export const SUBSCRIBER_BATCH_SIZE = 14;

export function radialPoint(centerX, centerY, radius, angleDegrees) {
    const angle = (Number(angleDegrees) * Math.PI) / 180;
    return {
        x: centerX + radius * Math.cos(angle),
        y: centerY + radius * Math.sin(angle),
    };
}

export function radialTopologyPositions(width, height, subscriberCount = 0) {
    const safeWidth = Math.max(1, Number(width) || 1);
    const safeHeight = Math.max(1, Number(height) || 1);
    const center = { x: safeWidth / 2, y: safeHeight / 2 };
    const serviceRadius = Math.min(safeWidth, safeHeight) * 0.28;
    const endpointRadius = Math.min(safeWidth, safeHeight) * 0.41;

    const positions = {
        internet: radialPoint(center.x, center.y, serviceRadius, -90),
        'group-queue': radialPoint(center.x, center.y, serviceRadius, 150),
        'group-pppoe': radialPoint(center.x, center.y, serviceRadius, 30),
        router: center,
    };

    const count = Math.max(0, Number(subscriberCount) || 0);
    for (let index = 0; index < count; index += 1) {
        const spread = Math.min(110, Math.max(54, count * 6));
        const start = 150 - spread / 2;
        positions[`subscriber-${index}`] = radialPoint(center.x, center.y, endpointRadius, start + (index * spread) / Math.max(1, count - 1));
    }

    return positions;
}

export function subscriberHasTraffic(node) {
    return Number(node?.upload_bytes ?? 0) > 0 || Number(node?.download_bytes ?? 0) > 0;
}

export function subscriberSearchMatch(node, query) {
    const term = String(query ?? '').trim().toLowerCase();
    if (!term) return false;

    return [node?.name, node?.target]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(term));
}

export function truncateTopologyLabel(value, maximum = 18) {
    const text = String(value ?? '');
    if (text.length <= maximum) return text;
    return `${text.slice(0, Math.max(1, maximum - 1))}…`;
}

export function visibleSubscriberNodes(nodes = [], limit = SUBSCRIBER_BATCH_SIZE, focusedId = null) {
    const safeLimit = Math.max(SUBSCRIBER_BATCH_SIZE, Number(limit) || SUBSCRIBER_BATCH_SIZE);
    const visible = nodes.slice(0, safeLimit);
    if (focusedId && !visible.some((node) => node.id === focusedId)) {
        const focused = nodes.find((node) => node.id === focusedId);
        if (focused) return [...visible.slice(0, Math.max(0, safeLimit - 1)), focused];
    }

    return visible;
}

export function remainingSubscriberCount(nodes = [], limit = SUBSCRIBER_BATCH_SIZE) {
    return Math.max(0, nodes.length - Math.max(0, Number(limit) || 0));
}

export function hasRecentTraffic(rows = []) {
    return rows.some((row) => Number(row?.upload_bytes ?? 0) > 0 || Number(row?.download_bytes ?? 0) > 0);
}