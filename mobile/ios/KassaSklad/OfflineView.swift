import UIKit

/// Экран «Нет связи»: касса ещё не открывалась на этом устройстве, а сервер недоступен.
final class OfflineView: UIView {
    var onRetry: (() -> Void)?

    private let messageLabel = UILabel()
    private let spinner = UIActivityIndicatorView(style: .medium)

    static let defaultMessage = """
    Проверьте интернет. Как только связь появится, касса откроется сама.

    Чеки, пробитые без связи, сохранены на этом телефоне и уйдут на сервер автоматически.
    """

    override init(frame: CGRect) {
        super.init(frame: frame)
        backgroundColor = UIColor(named: "LaunchBackground")

        let icon = UIImageView(image: UIImage(systemName: "wifi.slash"))
        icon.tintColor = UIColor(named: "AccentColor")
        icon.contentMode = .scaleAspectFit
        icon.preferredSymbolConfiguration = UIImage.SymbolConfiguration(pointSize: 52, weight: .regular)

        let title = UILabel()
        title.text = "Нет связи с сервером"
        title.font = .preferredFont(forTextStyle: .title2).withBold()
        title.textAlignment = .center
        title.numberOfLines = 0
        title.adjustsFontForContentSizeCategory = true

        messageLabel.text = Self.defaultMessage
        messageLabel.font = .preferredFont(forTextStyle: .body)
        messageLabel.textColor = .secondaryLabel
        messageLabel.textAlignment = .center
        messageLabel.numberOfLines = 0
        messageLabel.adjustsFontForContentSizeCategory = true

        var config = UIButton.Configuration.filled()
        config.title = "Повторить"
        config.cornerStyle = .large
        config.contentInsets = NSDirectionalEdgeInsets(top: 12, leading: 32, bottom: 12, trailing: 32)
        let retry = UIButton(configuration: config, primaryAction: UIAction { [weak self] _ in self?.onRetry?() })

        spinner.hidesWhenStopped = true

        let stack = UIStackView(arrangedSubviews: [icon, title, messageLabel, retry, spinner])
        stack.axis = .vertical
        stack.alignment = .center
        stack.spacing = 14
        stack.setCustomSpacing(24, after: messageLabel)
        stack.translatesAutoresizingMaskIntoConstraints = false
        addSubview(stack)
        NSLayoutConstraint.activate([
            stack.centerYAnchor.constraint(equalTo: safeAreaLayoutGuide.centerYAnchor),
            stack.leadingAnchor.constraint(greaterThanOrEqualTo: layoutMarginsGuide.leadingAnchor, constant: 8),
            stack.trailingAnchor.constraint(lessThanOrEqualTo: layoutMarginsGuide.trailingAnchor, constant: -8),
            stack.centerXAnchor.constraint(equalTo: centerXAnchor),
            stack.widthAnchor.constraint(lessThanOrEqualToConstant: 440),
        ])
    }

    @available(*, unavailable)
    required init?(coder: NSCoder) { fatalError("init(coder:) не используется") }

    func show(message: String) {
        messageLabel.text = message
        spinner.stopAnimating()
        isHidden = false
    }

    func setLoading(_ loading: Bool) {
        if loading { spinner.startAnimating() } else { spinner.stopAnimating() }
    }
}

private extension UIFont {
    func withBold() -> UIFont {
        guard let descriptor = fontDescriptor.withSymbolicTraits(.traitBold) else { return self }
        return UIFont(descriptor: descriptor, size: 0)
    }
}
