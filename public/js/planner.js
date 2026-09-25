// planner.js - Flywheel Daily Planner Initialization

document.addEventListener('DOMContentLoaded', () => {
    const cnt = document.getElementById('plannerPageContent');
    if (cnt) {
        const riskCap = typeof userRiskCap !== 'undefined' ? userRiskCap : 10000;
        fetch(`/api/flywheel/daily-planner?riskCap=${riskCap}`)
            .then(res => res.json())
            .then(json => {
                if (json.status === 'success' && json.data) {
                    if (typeof updateRiskBannerDisplay === 'function') {
                        updateRiskBannerDisplay(json.data.riskSummary);
                    }
                    if (typeof renderFlywheelPlannerContent === 'function') {
                        renderFlywheelPlannerContent(json.data, cnt);
                    }
                }
            })
            .catch(e => console.error(e));
    }
});
