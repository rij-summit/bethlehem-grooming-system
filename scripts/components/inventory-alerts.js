var InventoryAlerts = {
  expiryStyle(item) {
    if (item.is_expired || item.days_until_expiry <= 7) return "background-color:#fee2e2;color:#b91c1c";
    if (item.days_until_expiry <= 14) return "background-color:#fef3c7;color:#92400e";
    return "background-color:#fef9c3;color:#a16207";
  },
  expiryLabel(item) {
    if (item.is_expired) return "Expired";
    if (item.days_until_expiry === 0) return "Expires today";
    return `${item.days_until_expiry}d left`;
  },
};
